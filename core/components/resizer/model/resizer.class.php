<?php
/**
 * Resizer
 * Copyright 2013 Jason Grant
 * Please see the GitHub page for documentation or to report bugs:
 * https://github.com/oo12/Resizer
 *
 * Resizer is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option) any
 * later version.
 *
 * Resizer is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR
 * A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * Resizer; if not, write to the Free Software Foundation, Inc., 59 Temple
 * Place, Suite 330, Boston, MA 02111-1307 USA
 **/

function imagineLoader($class) {
	if (substr($class, 0, 8) === 'Imagine\\') {  // ignore anything else
		require MODX_CORE_PATH . 'components/resizer/model/' . str_replace('\\', '/', $class) . '.php';
	}
}
spl_autoload_register('\imagineLoader');


class Resizer {

public $debugmessages = array('Resizer v0.4.1');
public $debug = false;  //enable generation of debugging messages
public $defaultQuality = 80;

protected $modx;
protected $imagine;

private $palette;
private $topLeft;
private $basePathPlusUrl;
private $maxsize = false;

/*
 * Makes a token effort to look for a file
 * $src   string   path+filename to look for
 *
 * Returns path+filename on success, false if it can't find the file
 */
protected function findFile($src) {
	$file = MODX_BASE_PATH . ltrim(rawurldecode($src), '/');
	$file = str_replace($this->basePathPlusUrl, MODX_BASE_PATH, $file);  // if MODX is in a subdir, keep this subdir name from occuring twice
	if (is_readable($file)) {
		return $file;
	}
	return false;
}

/*
 * Positions an image within a container
 * $opt				String	position parameter: c, tl, br, etc.
 * $containerDims	Array	Container width, height
 * $imageDims		Array	Image width, height
 *
 * Returns a Point with the coordinates of the top left corner
 */
protected function position($opt, $containerDims, $imageDims) {
	$opt = strtolower($opt);
	if ($opt == 1 || $opt === 'c') {  // center is most common
		$x = (int) (($containerDims[0] - $imageDims[0]) / 2);
		$y = (int) (($containerDims[1] - $imageDims[1]) / 2);
	}
	elseif ($opt === 'tl') {
		$x = 0;
		$y = 0;
	}
	elseif ($opt === 't') {
		$x = (int) (($containerDims[0] - $imageDims[0]) / 2);
		$y = 0;
	}
	elseif ($opt === 'tr') {
		$x = $containerDims[0] - $imageDims[0];
		$y = 0;
	}
	elseif ($opt === 'l') {
		$x = 0;
		$y = (int) (($containerDims[1] - $imageDims[1]) / 2);
	}
	elseif ($opt === 'r')  {
		$x = $containerDims[0] - $imageDims[0];
		$y = (int) (($containerDims[1] - $imageDims[1]) / 2);
	}
	elseif ($opt === 'bl')  {
		$x = 0;
		$y = $containerDims[1] - $imageDims[1];
	}
	elseif ($opt === 'b')  {
		$x = (int) (($containerDims[0] - $imageDims[0]) / 2);
		$y = $containerDims[1] - $imageDims[1];
	}
	elseif ($opt === 'br')  {
		$x = $containerDims[0] - $imageDims[0];
		$y = $containerDims[1] - $imageDims[1];
	}
	else {  // otherwise same as center
		$x = (int) (($containerDims[0] - $imageDims[0]) / 2);
		$y = (int) (($containerDims[1] - $imageDims[1]) / 2);
	}
	return new Imagine\Image\Point($x, $y);
}


/*
 * @param  modX $modx
 * @param  int  $graphicsLib  (optional) specify a preferred graphics library
 *							  2: Auto/Gmagick, 1: Imagick, 0: GD
 */
public function __construct(modX &$modx, $graphicsLib = true) {
	$this->modx =& $modx;
	if ($graphicsLib === true) {  // if a preference isn't specified, get it from system settings
		$graphicsLib = $this->modx->getOption('resizer.graphics_library', NULL, 2);
	}
	// Decide which graphics library to use and create the appropriate Imagine object
	if (class_exists('Gmagick', false) && $graphicsLib > 1) {
		$this->debugmessages[] = 'Using Gmagick';
		set_time_limit(0);
		$this->imagine = new \Imagine\Gmagick\Imagine();
	}
	elseif (class_exists('Imagick', false) && $graphicsLib) {
		$this->debugmessages[] = 'Using Imagick';
		set_time_limit(0);  // execution time accounting seems strange on some systems. Maybe because of multi-threading?
		$this->imagine = new \Imagine\Imagick\Imagine();
	}
	else {  // good ol' GD
		$this->debugmessages[] = 'Using GD';
		$this->imagine = new \Imagine\Gd\Imagine();
		$this->maxsize = ini_get('memory_limit');
		$magnitude = strtoupper(substr($this->maxsize, -1));
		if ($magnitude === 'G')  { $this->maxsize *= 1024; }
		elseif ($magnitude === 'K')  { $this->maxsize /= 1024; }
		$this->maxsize = ($this->maxsize - 18) * 209715;  // 20% of memory_limit, in bytes. -18MB for MODX and PHP overhead
	}
	$this->basePathPlusUrl = MODX_BASE_PATH . ltrim(MODX_BASE_URL, '/');  // used to weed out duplicate subdirs
}


/*
 * Cut off all image-specific debug messages. Useful when you're reusing the same
 * Resizer object to process multiple images
 */
public function resetDebug() {
	$this->debugmessages = array_slice($this->debugmessages, 0, 2);
}


/*
 * Read image from $input, process it according to $options and write it to $output
 *
 * @param  string  $input    filename of input image
 * @param  string  $output   filename to write output image to. Image format determined by $output's extension.
 * @param  array   $options  options array or string, phpThumb style
 *
 * Returns true/false or success/failure
 */
public function processImage($input, $output, $options = array()) {
	if ( !is_readable($input) && !($input = $this->findFile($input)) ) {
		$this->debugmessages[] = 'File not ' . (file_exists($input) ? 'readable': 'found') . ": $input  *** Skipping ***";
		return false;
	}
	if ($this->debug) {
		$optionsOriginal = is_string($options) ? parse_str($options) : $options;
		$startTime = microtime(true);
	}
	if ($this->maxsize) {  // if we're using GD we need to check the image will fit in memory
		$imagesize = @GetImageSize($input);
		if ($imagesize && $imagesize[0] * $imagesize[1] > $this->maxsize) {  // if there's no size we'll just go for it
			$this->debugmessages[] = "GD: $input may exceed available memory  ** Skipping **";
			return false;
		}
	}
	if (is_string($options)) {  // convert an options string to an array if needed
		$options = parse_str($options);
	}
	$outputIsJpg = strncasecmp('jp', pathinfo($input, PATHINFO_EXTENSION), 2) === 0;  // extension determines image format
	try {
		$image = $this->imagine->open($input);

/* initial dimensions */
		$size = $image->getSize();
		$origWidth = $size->getWidth();
		$origHeight = $size->getHeight();

		// use width/height if specified
		if (isset($options['w']))  { $width = $options['w']; }
		if (isset($options['h']))  { $height = $options['h']; }

		$origAR = $origWidth / $origHeight;  // original image aspect ratio

		// override with any orientation-specific dimensions
		$aspect = round($origAR, 2);
		if ($aspect > 1) {  // landscape
			if (isset($options['wl']))  { $width = $options['wl']; }
			if (isset($options['hl']))  { $height = $options['hl']; }
		}
		elseif ($aspect < 1) {  // portrait
			if (isset($options['wp']))  { $width = $options['wp']; }
			if (isset($options['hp']))  { $height = $options['hp']; }
		}
		else {  // square
			if (isset($options['ws']))  { $width = $options['ws']; }
			if (isset($options['hs']))  { $height = $options['hs']; }
		}

		// fill in a missing dimension
		$bothDims = true;
		if (empty($width)) {
			if (empty($height))  {
				$height = $origHeight;
				$width = $origWidth;
			}
			else {
				$width = $height * $origAR;
			}
			$bothDims = false;
		}
		if (empty($height)) {
			$height = $width / $origAR;
			$bothDims = false;
		}

/* scale */
		if (!empty($options['scale'])) {
			if (empty($options['aoe'])) {  // if aoe is off, cap scale so image isn't enlarged
				$hScale = $origHeight / $height;
				$wScale = $origWidth / $width;
				$wRequested = $width * $options['scale'];  // we'll need these for quality scaling
				$hRequested = $height * $options['scale'];
				$options['scale'] = ($hScale > 1 && $wScale > 1) ? min($hScale, $wScale, $options['scale']) : 1;
			}
			$width = $width * $options['scale'];
			$height = $height * $options['scale'];
		}

		$newAR = $width / $height;

		$hasBG = false;
/* bg - start */
		if ( (!empty($options['bg']) && strncasecmp('jp', pathinfo($input, PATHINFO_EXTENSION), 2) !== 0) ||
			 (!empty($options['far']) && $bothDims && empty($options['zc'])) ) {
			if (!isset($this->palette)) {
				$this->palette = new Imagine\Image\Palette\RGB();
				$this->topLeft = new Imagine\Image\Point(0, 0);
			}
			if (!empty($options['bg'])) {
				$bgColor = explode('/', $options['bg']);
				$bgColor[1] = isset($bgColor[1]) ? $bgColor[1] : 100;
			}
			else {
				$bgColor = array('ffffff', 100);
			}
			$backgroundColor = $this->palette->color($bgColor[0], 100 - $bgColor[1]);
			$hasBG = true;
		}

		if (empty($options['zc']) || !$bothDims) {
			$options['w'] = $width;
			$options['h'] = $height;

/* non-zc cropping */
			if (isset($options['sw']) || isset($options['sh'])) {
				if (empty($options['sw']) || $options['sw'] > $origWidth) {
					$newWidth = $origWidth;
				}
				else {
					$newWidth = $options['sw'] < 1 ? round($origWidth * $options['sw']) : $options['sw'];  // sw < 1 is a %, >= 1 in px
				}

				if (empty($options['sh']) || $options['sh'] > $origHeight) {
					$newHeight = $origHeight;
				}
				else {
					$newHeight = $options['sh'] < 1 ? round($origHeight * $options['sh']) : $options['sh'];
				}

				if (empty($options['sx'])) {
					$cropStartX = isset($options['sx']) ? $options['sx'] : (int) (($origWidth - $newWidth) / 2);  // 0 or center
				}
				else {
					$cropStartX = $options['sx'] < 1 ? round($width * $options['sx']) : $options['sx'];
					if ($cropStartX + $newWidth > $origWidth)  { $cropStartX = $origWidth - $newWidth; }  // crop box can't go past the right edge
				}
				if (empty($options['sy'])) {
					$cropStartY = isset($options['sy']) ? $options['sy'] : (int) (($origHeight - $newHeight) / 2);
				}
				else {
					$cropStartY = $options['sy'] < 1 ? round($height * $options['sy']) : $options['sy'];
					if ($cropStartY + $newHeight > $origHeight)  { $cropStartY = $origHeight - $newHeight; }
				}
				$cropStart = new Imagine\Image\Point($cropStartX, $cropStartY);
				$cropBox = new Imagine\Image\Box($newWidth, $newHeight);
				$image->crop($cropStart, $cropBox);
				unset($cropBox);
				$origWidth = $newWidth;
				$origHeight = $newHeight;
				$origAR = $origWidth / $origHeight;
				if (($width > $origWidth || $height > $origHeight) && empty($options['aoe'])) {  // adjust output size if it's too big
					$width = $origWidth;
					$height = $origHeight;
					if ($newAR < $origAR)  { $width = round($origHeight * $newAR); }
					elseif ($newAR > $origAR)  { $height = round($origWidth / $newAR); }
					$this->debugmessages[] = "w: $width, h: $height, ow: $origWidth, oh: $origHeight";
				}
				else {
					$width = round($width);  // clean up
					$height = round($height);
				}
			}
			else {
				if ($newAR < $origAR)  { $height = $width / $origAR; }  // Make sure AR doesn't change. Smaller dimension...
				elseif ($newAR > $origAR)  { $width = $height * $origAR; }  // ...limits larger
				$width = round($width);  // clean up
				$height = round($height);
/* far */
				if (!empty($options['far']) && $bothDims) {
					$options['w'] = round($options['w']);
					$options['h'] = round($options['h']);
					$farPoint = $this->position($options['far'], array($options['w'], $options['h']), array($width, $height));
					$farBox = new Imagine\Image\Box($options['w'], $options['h']);
				}
			}
		}
		else {
/* zc */
			if (empty($options['aoe'])) {
				// if the crop box is bigger than the original image, scale it down
				if ($width > $origWidth) {
					$height = $origWidth / $newAR;
					$width = $origWidth;
				}
				if ($height > $origHeight) {
					$width = $origHeight * $newAR;
					$height = $origHeight;
				}
			}

			// make sure final image will cover the crop box
			if ($height * $origAR > $width)  {  // needs horizontal cropping
				$newWidth = round($height * $origAR);
				$width = round($width);
				$newHeight = $height = round($height);
			}
			elseif ($width / $origAR > $height)  {  // needs vertical cropping
				$newHeight = round($width / $origAR);
				$height = round($height);
				$newWidth = $width = round($width);
			}
			else {  // no cropping needed, same AR
				$newWidth = $width = round($width);
				$newHeight = $height = round($height);
			}

			$cropStart = $this->position($options['zc'], array($newWidth, $newHeight), array($width, $height));
			$cropBox = new Imagine\Image\Box($width, $height);
			$width = $newWidth;
			$height = $newHeight;
		}

/* resize, aoe */
		if ( ($width < $origWidth && $height < $origHeight) || !empty($options['aoe']) ) {
			$imgBox = new Imagine\Image\Box($width, $height);
			$image->scale($imgBox);
			$didScale = true;
		}
/* qmax */
		elseif (isset($options['qmax']) && $outputIsJpg && empty($options['aoe']) && isset($options['q'])) {
			// undersized input image. We'll increase q towards qmax depending on how much it's undersized
			$sizeRatio = $origWidth * $origHeight / (isset($wRequested) ? ($wRequested * $hRequested) : ($width * $height));
			if ($sizeRatio > 0.5) {  // if new image has more that 1/2 the resolution of the requested size
				$options['q'] += round(($options['qmax'] - $options['q']) * (1 - $sizeRatio) * 2);
			}
			else { $options['q'] = $options['qmax']; }  // otherwise qmax
		}


/* strip */
		if (!empty($options['strip'])) {  // convert to sRGB, remove any ICC profile and metadata
			$image->strip();
		}

/* filters */
		if (!empty($options['fltr'])) {
			if (!is_array($options['fltr'])) {
				$options['fltr'] = array($options['fltr']);  // in case somebody did fltr= instead of fltr[]=
			}
			foreach($options['fltr'] as $fltr) {
				$filter = explode('|', $fltr);
				if ($filter[0] === 'usm') {  // right now only unsharp mask is implemented, sort of
					$image->effects()->sharpen();  // radius, amount and threshold are ignored!
				}
			}
		}

/* bg - finish */
		if ($hasBG) {
			$image = $this->imagine->create(
				isset($farBox) ? $farBox : (isset($imgBox) ? $imgBox : new Imagine\Image\Box($width, $height)),
				$this->palette->color($bgColor[0], 100 - $bgColor[1])
			)->paste(
				$image,
				isset($farPoint) ? $farPoint : $this->topLeft
			);
		}

/* crop */
		if (isset($cropBox)) {
			$image->crop($cropStart, $cropBox);
/* debug info */
		if ($this->debug) {
			$debugTime = microtime(true);
			$this->debugmessages[] = 'Input options:' . substr(var_export($inputParams['options'], true), 7, -3);  // print all options, stripping off array()
			$changed = array();  // note any options which may have been changed during processing
			foreach (array('w', 'h', 'scale', 'q') as $opt) {
				if ($inputParams['options'][$opt] != $options[$opt])  { $changed[$opt] = $options[$opt]; }
			}
			if ($changed) {
				$this->debugmessages[] = 'Modified options:' . substr(var_export($changed, true), 7, -3);
			}
			$this->debugmessages[] = "Original - w: {$inputParams['width']} | h: {$inputParams['height']} " . sprintf("(%2.2f MP)", $inputParams['width'] * $inputParams['height'] / 1e6);
			if (isset($scBox)) {
				$this->debugmessages[] = "Source area - start: ($cropStartX, $cropStartY) | box: {$scBox->getWidth()} x {$scBox->getHeight()}";
			}
			if (isset($wRequested)) {
				$this->debugmessages[] = "Requested - w: " . round($wRequested) . ' | h: ' . round($hRequested);
			}
			if (!isset($wRequested) || !$didScale) {
				$this->debugmessages[] = "New - w: $width | h: $height" . ($didScale ? '' : ' [Not scaled: same size or insufficient input resolution]');
			}
			if (isset($farPoint)) {
				$this->debugmessages[] = "FAR - start: ({$farPoint->getX()},{$farPoint->getY()}) | box: {$options['w']} x {$options['h']}";
			}
			if (isset($cropBox)) {
				$this->debugmessages[] = "ZC - start: ({$cropStart->getX()},{$cropStart->getY()}) | box: {$cropBox->getWidth()} x {$cropBox->getHeight()}";
			}
			if ($hasBG) {
				$this->debugmessages[] = "Background color: {$bgColor[0]} | opacity: {$bgColor[1]}";
			}
			$debugTime = microtime(true) - $debugTime;
		}

/* save */
		$outputOpts = array('quality' => isset($options['q']) ? (int) $options['q'] : $this->defaultQuality);  // change 'q' to 'quality', or use default
		$image->save($output, $outputOpts);
	}
/* error handler */
	catch(Imagine\Exception\Exception $e) {
		$this->debugmessages[] = '*** Error *** ' . $e->getMessage();
		$this->debugmessages[] = "Input file: $input";
		$this->debugmessages[] = 'Input options: ' . substr(var_export($inputParams['options'], true), 7, -3);
		return false;
	}

/* debug info (timing) */
	if ($this->debug) {
		$this->debugmessages[] = "Wrote $output";
		$this->debugmessages[] = 'Execution time: ' . round((microtime(true) - $startTime - $debugTime) * 1e3) . ' ms';
	}
	return true;
}


}