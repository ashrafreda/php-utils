<?php
/**
 * Image gradient generator.
 *  Take a width or height, and strings like this:
 *   linear-gradient(top, rgba(255,255,255,.2), rgba(255,255,255,.2) 1px, rgba(255,255,255,.05) 1px, rgba(255,255,255,0) 50%, rgba(0,0,0,0) 50%, rgba(0,0,0,.05))
 *  and return an image.
 *
 * Does not handle the legacy webkit syntax, but can handle sides specified without 'to'
 *
 * Example:
 *  $grad = new LinearGradient('linear-gradient(top, hsla(120,100%,50%,.5), #f00, rgba(255,255,255,0))');
 *  $grad->render(100, 100, 'png', 'test.png');
 *
 * Copyright (c) 2012 Andy VanWagoner
 * Licensed under MIT and BSD Licenses
 * https://github.com/thetalecrafter/php-utils
 **/

class LinearGradient {

	protected
		$string = 'linear-gradient(#000,#fff)',
		$dir = 'to bottom',
		$stops = array();

	public function __construct($linear_gradient='') {
		$this->parse($linear_gradient ? $linear_gradient : $this->string);
	}

	public function __set($name, $value) {
		if ($name === 'string') return $this->parse($value);
		if ($name === 'dir') return $this->dir = $value;
	}

	public function __get($name) {
		if ($name === 'string') return $this->string;
		if ($name === 'stops') return $this->stops;
		if ($name === 'dir') return $this->dir;
		return null;
	}


	/**
	 * Render the gradient to an image
	 **/
	public function render($width, $height, $format='png', $filename=null) {
		$image = $this->render_gd($width, $height);
		switch ($format) {
			case 'jpg': case 'jpeg': case 'jpe':
				if (empty($filename)) header('Content-Type: image/jpeg');
				imagejpeg($image, $filename, 90);
				break;
			case 'gif':
				if (empty($filename)) header('Content-Type: image/gif');
				imagegif($image, $filename);
				break;
			case 'png': default:
				if (empty($filename)) header('Content-Type: image/png');
				imagepng($image, $filename, 9);
				break;
		}
		imagedestroy($image);
	}

	public function render_gd($width, $height) {
		$angle = $this->parse_angle($this->dir, $width, $height);

		list( $gwidth, $gheight ) = $this->gradient_size($angle, $width, $height);
		$image = imagecreatetruecolor($gwidth, $gheight);
		imagealphablending($image, false);
		imagesavealpha($image, true);
		$transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
		imagefill($image, 0, 0, $transparent);

		$i = 0;
		$stops = $this->position_stops($gheight);
		foreach ($stops as $stop) {
			$stop->color->red *= $stop->color->alpha;
			$stop->color->green *= $stop->color->alpha;
			$stop->color->blue *= $stop->color->alpha;
		}

		for ($p = $stops[0]->position; $p < $gheight; ++$p) {
			while (isset($stops[$i + 1]) && $stops[$i + 1]->position == $p) ++$i;

			$color1 = $stops[$i]->color;
			$color2 = $stops[$i + 1]->color;
			$distance = $stops[$i]->position - $stops[$i + 1]->position;
			$t = abs(($p - $stops[$i]->position) / $distance);
			$alpha = $color1->alpha + ($color2->alpha - $color1->alpha) * $t;
			$red = $alpha ? ($color1->red + ($color2->red - $color1->red) * $t) / $alpha : 0;
			$green = $alpha ? ($color1->green + ($color2->green - $color1->green) * $t) / $alpha : 0;
			$blue = $alpha ? ($color1->blue + ($color2->blue - $color1->blue) * $t) / $alpha : 0;
			$color = imagecolorallocatealpha($image, $red, $green, $blue, 127 - ($alpha * 127));

			imageline($image, 0, $p, $gwidth, $p, $color);
		}

		if (abs(180 - $angle) >= .05) { // within .05 degrees of down
			$tmp_image = imagerotate($image, 180 - $angle, $transparent);
			imagealphablending($tmp_image, false);
			imagesavealpha($tmp_image, true);
			imagedestroy($image);
			$gwidth = imagesx($tmp_image);
			$gheight = imagesy($tmp_image);
			$image = imagecreatetruecolor($width, $height);
			imagealphablending($image, false);
			imagesavealpha($image, true);
			$transparent = imagecolorallocatealpha($image, 0, 0, 0, 0);
			imagefill($image, 0, 0, $transparent);
			imagecopy($image, $tmp_image, 0, 0, ($gwidth - $width) / 2, ($gheight - $height) / 2, $width, $height);
			imagedestroy($tmp_image);
		}

		return $image;
	}

	protected function gradient_size($angle, $width, $height) {
		if (abs(0 - $angle) < .05 || abs(180 - $angle) < .05 || abs(360 - $angle) < .05) return array( $width, $height );
		if (abs(90 - $angle) < .05 || abs(270 - $angle) < .05) return array( $height, $width );

		$w = $width / 2; $h = $height / 2;
		$points = array( array( -$w, -$h ), array( $w, -$h ), array( $w, $h ), array( -$w, $h ) );
		$sin = sin(deg2rad($angle));
		$cos = cos(deg2rad($angle));
		$max_x = 0; $min_x = 0;
		$max_y = 0; $min_y = 0;
		foreach ($points as $i => $point) {
			$x = $point[0] * $cos - $point[1] * $sin;
			$y = $point[0] * $sin + $point[1] * $cos;
			$max_x = max($max_x, $x);
			$min_x = min($min_x, $x);
			$max_y = max($max_y, $y);
			$min_y = min($min_y, $y);
		}
		// make it 4 pixels bigger to make sure we cover the corners all the way
		return array( ceil($max_x - $min_x) + 4, ceil($max_y - $min_y) + 4 );
	}

	protected function position_stops($size) {
		$stops = $this->stops;
		$l = count($stops) - 1;
		if ($l == 0) { // create end same as begining if only one specified
			$stops[++$l] = clone $stops[0];
			$stops[$l]->position = null;
		}

		$stop = clone $stops[$l]; // set end
		if ($stop->position === null) $stop->position = $size;
		else $stop->position = $this->to_pixels($stop->position, $size);
		$stops[$l] = $stop;

		$stop = clone $stops[0]; // set start
		if ($stop->position === null) $stop->position = 0;
		else $stop->position = $this->to_pixels($stop->position, $size);
		$stops[0] = $stop;

		$pos = $stop->position; // starting position
		for ($i = 1; $i < $l; ++$i) {
			if ($stops[$i]->position !== null) {
				$stop = clone $stops[$i];
				$stop->position = $this->to_pixels($stop->position, $size);
				$stops[$i] = $stop;
			} else {
				$k = $i;
				while ($stops[$k]->position === null) ++$k;

				$begin = $stops[$i - 1]->position;
				$end =  $this->to_pixels($stops[$k]->position, $size);
				$step = ($end - $begin) / ($k - $i + 1);
				$n = 1;

				while ($i < $k) {
					$stop = clone $stops[$i];
					$stop->position = (int)round($begin + ($step * $n++));
					$stops[$i++] = $stop;
				}
			}
		}

		return $stops;
	}

	protected function to_pixels($pos, $size) {
		if (is_numeric($pos)) return floor($pos);
		if ($pos->unit == '%') return floor($pos->value / 100 * $size);
		if (isset($this->abs_units[$pos->unit])) {
			return floor($pos->value * $this->abs_units[$pos->unit]);
		}
		return false;
	}


	/**
	 * Parse the linear-gradient syntax
	 **/
	protected function parse($linear_gradient) {
		$linear_gradient = trim($linear_gradient);
		if (substr($linear_gradient, 0, strlen('linear-gradient(')) != 'linear-gradient(') throw new Exception(1, 'must start with linear-gradient(');
		$params = substr($linear_gradient, strlen('linear-gradient('), -1);

		$params = preg_replace('/\s+/', ' ', trim($params)); // normalize white-space
		// remove unneeded spaces
		$params = str_replace(array( ' ( ', ' (', '( ' ), '(', $params);
		$params = str_replace(array( ' , ', ' ,', ', ' ), ',', $params);
		$params = str_replace(' )', ')', $params);

		// swap commas to tilde inside rgb() syntax so we can explode
		$params = preg_replace('/\(([^,)]+),([^,)]+),([^,)]+),?/', '($1~$2~$3~', $params);
		$params = str_replace('~)', ')', $params);

		$stops = explode(',', $params);
		if (empty($stops)) throw new Exception(2, 'must have at least one parameter');
		foreach ($stops as $i => $stop) { $stops[$i] = str_replace('~', ',', trim($stop)); } // unswap comma and tilde
		if ( ! $this->parse_color($stops[0])) {
			$this->dir = trim($stops[0]);
			$stops = array_slice($stops, 1);
		}

		foreach ($stops as $i => $stop) { $stops[$i] = $this->parse_stop($stop); }

		$this->string = $linear_gradient;
		$this->stops = $stops;
	}


	/**
	 * Code to parse angles
	 **/
	protected function parse_angle($angle, $width, $height) {
		if (isset($this->side_angles[$angle])) $angle = $this->side_angles[$angle];

		if (isset($this->corner_angles[$angle])) {
			$dir = $this->corner_angles[$angle];
			switch ($dir) {
				case 'lt': return rad2deg(atan($height / $width)) + 270;
				case 'rt': return rad2deg(atan($width / $height));
				case 'rb': return rad2deg(atan($height / $width)) + 90;
				case 'lb': return rad2deg(atan($width / $height)) + 180;
				case 'tlt': return rad2deg(atan($width / $height)) + 270;
				case 'trt': return rad2deg(atan($height / $width));
				case 'trb': return rad2deg(atan($width / $height)) + 90;
				case 'tlb': return rad2deg(atan($height / $width)) + 180;
				default: break;
			}
		}

		preg_match('/^([0-9.-]+)(g?rad|deg|turn)$/i', $angle, $matches);
		if ($matches) {
			switch ($matches[2]) {
				case 'rad': return ($matches[1] / M_PI * 180) % 360;
				case 'deg': return (float)$matches[1] % 360;
				case 'grad': return ($matches[1] * .9) % 360;
				case 'turn': return ($matches[1] / 360) % 360;
				default: return false;
			}
		}

		return false;
	}

	protected $side_angles = array(
		'top'=>'180deg', 'to bottom'=>'180deg',
		'bottom'=>'0deg', 'to top'=>'0deg',
		'left'=>'90deg', 'to right'=>'90deg',
		'right'=>'270deg', 'to left'=>'270deg'
	);

	protected $corner_angles = array(
		'right bottom'=>'lt', 'bottom right'=>'lt', 'to left top'=>'tlt', 'to top left'=>'tlt',
		'left bottom'=>'rt', 'bottom left'=>'rt', 'to right top'=>'trt', 'to top right'=>'trt',
		'left top'=>'rb', 'top left'=>'rb', 'to right bottom'=>'trb', 'to bottom right'=>'trb',
		'right top'=>'lb', 'top right'=>'lb', 'to left bottom'=>'tlb', 'to bottom left'=>'tlb'
	);

	/**
	 * Code to parse color stops = colors, lengths, and percentages
	 **/
	protected function parse_stop($stop) {
		$stop = explode(' ', $stop);
		$color = $this->parse_color($stop[0]);
		$pos = $this->parse_length(isset($stop[1]) ? $stop[1] : null);
		return (object)array( 'color'=>$color, 'position'=>$pos );
	}

	protected function parse_length($length) {
		$pos = null;
		if (is_numeric($length)) {
			$pos = (object)array( 'value'=>($length * 100), 'unit'=>'%' );
		} else if (isset($length)) {
			preg_match('/^([0-9.]+)([%a-z]+)$/i', $length, $matches);
			if ($matches) {
				if (isset($this->abs_units[$matches[2]])) {
					$pos = (object)array( 'value'=>(float)$matches[1], 'unit'=>$matches[2] );
				} else if ($matches[2] === '%') {
					$pos = (object)array( 'value'=>(float)$matches[1], 'unit'=>'%' );
				}
			}
		}
		return $pos;
	}

	protected $abs_units = array( 'px'=>1, 'in'=>96, 'mm'=>3.77952756, 'cm'=>37.7952756, 'pt'=>1.3333333, 'pc'=>16 );

	public function parse_color($color) {
		if (isset($this->named_colors[$color])) $color = $this->named_colors[$color];
		if ($color[0] === '#') return $this->parse_hex($color);
		if (stripos($color, 'rgb') === 0) return $this->parse_rgb($color);
		if (stripos($color, 'hsl') === 0) return $this->parse_hsl($color);
		return false;
	}

	public function parse_hex($hex) {
		$hex = strtolower(substr($hex, 1));
		if (strlen($hex) == 3) $hex = ($hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2]);
		$red   = hexdec(substr($hex, 0, 2));
		$green = hexdec(substr($hex, 2, 2));
		$blue  = hexdec(substr($hex, 4, 2));
		return (object)array( 'red'=>$red, 'green'=>$green, 'blue'=>$blue, 'alpha'=>1 );
	}

	public function parse_rgb($rgb) {
		$rgb = substr($rgb, strpos($rgb, '(')+1, -1);
		list( $red, $green, $blue, $alpha ) = explode(',', $rgb);
		if ( ! isset($alpha)) $alpha = 1;
		return (object)array(
			'red'   => (int)max(0, min(255, $red)),
			'green' => (int)max(0, min(255, $green)),
			'blue'  => (int)max(0, min(255, $blue)),
			'alpha' => (float)max(0, min(1, $alpha))
		);
	}

	public function parse_hsl($hsl) {
		$hsl = str_replace('%', '', $hsl); // remove %
		$hsl = substr($hsl, strpos($hsl, '(')+1, -1);
		list( $h, $s, $l, $a ) = explode(',', $hsl);
		$s = max(0, min(1, $s / 100));
		$l = max(0, min(1, $l / 100));

		$m2 = ($l <= .5) ? $l * ($s + 1) : $l + $s - $l * $s;
		$m1 = $l * 2 - $m2;

		if ( ! isset($a)) $a = 1;
		return (object)array(
			'red'   => $this->hsl_helper($m1, $m2, $h + 120),
			'green' => $this->hsl_helper($m1, $m2, $h),
			'blue'  => $this->hsl_helper($m1, $m2, $h - 120),
			'alpha' => (float)max(0, min(1, $a))
		);
	}

	protected function hsl_helper($m1, $m2, $h) {
		$h = ($h % 360) / 360;
		if ($h * 6 < 1) $c = ($m1 + ($m2 - $m1) * $h * 6);
		else if ($h * 2 < 1) $c = $m2;
		else if ($h * 3 < 2) $c = ($m1 + ($m2 - $m1) * ( 2 / 3 - $h) * 6);
		else $c = $m1;
		return (int)max(0, min(255, round($c * 255)));
	}

	protected $named_colors = array(
		'transparent' => 'rgba(0,0,0,0)',
		'aliceblue' => '#F0F8FF',
		'antiquewhite' => '#FAEBD7',
		'aqua' => '#00FFFF',
		'aquamarine' => '#7FFFD4',
		'azure' => '#F0FFFF',
		'beige' => '#F5F5DC',
		'bisque' => '#FFE4C4',
		'black' => '#000000',
		'blanchedalmond' => '#FFEBCD',
		'blue' => '#0000FF',
		'blueviolet' => '#8A2BE2',
		'brown' => '#A52A2A',
		'burlywood' => '#DEB887',
		'cadetblue' => '#5F9EA0',
		'chartreuse' => '#7FFF00',
		'chocolate' => '#D2691E',
		'coral' => '#FF7F50',
		'cornflowerblue' => '#6495ED',
		'cornsilk' => '#FFF8DC',
		'crimson' => '#DC143C',
		'cyan' => '#00FFFF',
		'darkblue' => '#00008B',
		'darkcyan' => '#008B8B',
		'darkgoldenrod' => '#B8860B',
		'darkgray' => '#A9A9A9',
		'darkgreen' => '#006400',
		'darkgrey' => '#A9A9A9',
		'darkkhaki' => '#BDB76B',
		'darkmagenta' => '#8B008B',
		'darkolivegreen' => '#556B2F',
		'darkorange' => '#FF8C00',
		'darkorchid' => '#9932CC',
		'darkred' => '#8B0000',
		'darksalmon' => '#E9967A',
		'darkseagreen' => '#8FBC8F',
		'darkslateblue' => '#483D8B',
		'darkslategray' => '#2F4F4F',
		'darkslategrey' => '#2F4F4F',
		'darkturquoise' => '#00CED1',
		'darkviolet' => '#9400D3',
		'deeppink' => '#FF1493',
		'deepskyblue' => '#00BFFF',
		'dimgray' => '#696969',
		'dimgrey' => '#696969',
		'dodgerblue' => '#1E90FF',
		'firebrick' => '#B22222',
		'floralwhite' => '#FFFAF0',
		'forestgreen' => '#228B22',
		'fuchsia' => '#FF00FF',
		'gainsboro' => '#DCDCDC',
		'ghostwhite' => '#F8F8FF',
		'gold' => '#FFD700',
		'goldenrod' => '#DAA520',
		'gray' => '#808080',
		'green' => '#008000',
		'greenyellow' => '#ADFF2F',
		'grey' => '#808080',
		'honeydew' => '#F0FFF0',
		'hotpink' => '#FF69B4',
		'indianred' => '#CD5C5C',
		'indigo' => '#4B0082',
		'ivory' => '#FFFFF0',
		'khaki' => '#F0E68C',
		'lavender' => '#E6E6FA',
		'lavenderblush' => '#FFF0F5',
		'lawngreen' => '#7CFC00',
		'lemonchiffon' => '#FFFACD',
		'lightblue' => '#ADD8E6',
		'lightcoral' => '#F08080',
		'lightcyan' => '#E0FFFF',
		'lightgoldenrodyellow' => '#FAFAD2',
		'lightgray' => '#D3D3D3',
		'lightgreen' => '#90EE90',
		'lightgrey' => '#D3D3D3',
		'lightpink' => '#FFB6C1',
		'lightsalmon' => '#FFA07A',
		'lightseagreen' => '#20B2AA',
		'lightskyblue' => '#87CEFA',
		'lightslategray' => '#778899',
		'lightslategrey' => '#778899',
		'lightsteelblue' => '#B0C4DE',
		'lightyellow' => '#FFFFE0',
		'lime' => '#00FF00',
		'limegreen' => '#32CD32',
		'linen' => '#FAF0E6',
		'magenta' => '#FF00FF',
		'maroon' => '#800000',
		'mediumaquamarine' => '#66CDAA',
		'mediumblue' => '#0000CD',
		'mediumorchid' => '#BA55D3',
		'mediumpurple' => '#9370DB',
		'mediumseagreen' => '#3CB371',
		'mediumslateblue' => '#7B68EE',
		'mediumspringgreen' => '#00FA9A',
		'mediumturquoise' => '#48D1CC',
		'mediumvioletred' => '#C71585',
		'midnightblue' => '#191970',
		'mintcream' => '#F5FFFA',
		'mistyrose' => '#FFE4E1',
		'moccasin' => '#FFE4B5',
		'navajowhite' => '#FFDEAD',
		'navy' => '#000080',
		'oldlace' => '#FDF5E6',
		'olive' => '#808000',
		'olivedrab' => '#6B8E23',
		'orange' => '#FFA500',
		'orangered' => '#FF4500',
		'orchid' => '#DA70D6',
		'palegoldenrod' => '#EEE8AA',
		'palegreen' => '#98FB98',
		'paleturquoise' => '#AFEEEE',
		'palevioletred' => '#DB7093',
		'papayawhip' => '#FFEFD5',
		'peachpuff' => '#FFDAB9',
		'peru' => '#CD853F',
		'pink' => '#FFC0CB',
		'plum' => '#DDA0DD',
		'powderblue' => '#B0E0E6',
		'purple' => '#800080',
		'red' => '#FF0000',
		'rosybrown' => '#BC8F8F',
		'royalblue' => '#4169E1',
		'saddlebrown' => '#8B4513',
		'salmon' => '#FA8072',
		'sandybrown' => '#F4A460',
		'seagreen' => '#2E8B57',
		'seashell' => '#FFF5EE',
		'sienna' => '#A0522D',
		'silver' => '#C0C0C0',
		'skyblue' => '#87CEEB',
		'slateblue' => '#6A5ACD',
		'slategray' => '#708090',
		'slategrey' => '#708090',
		'snow' => '#FFFAFA',
		'springgreen' => '#00FF7F',
		'steelblue' => '#4682B4',
		'tan' => '#D2B48C',
		'teal' => '#008080',
		'thistle' => '#D8BFD8',
		'tomato' => '#FF6347',
		'turquoise' => '#40E0D0',
		'violet' => '#EE82EE',
		'wheat' => '#F5DEB3',
		'white' => '#FFFFFF',
		'whitesmoke' => '#F5F5F5',
		'yellow' => '#FFFF00',
		'yellowgreen' => '#9ACD32'
	);
}
