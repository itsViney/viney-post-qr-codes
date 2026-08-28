<?php
/**
 * Rounded GD output for QR codes.
 *
 * @package Viney\PostQRCodes
 */

namespace Viney\PostQRCodes;

use chillerlan\QRCode\Data\QRMatrix;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QROptions;
use chillerlan\Settings\SettingsContainerInterface;

use function imagefilledellipse;
use function imagefilledrectangle;
use function is_iterable;
use function max;

defined( 'ABSPATH' ) || exit;

final class QRGdStyledRounded extends QRGdImagePNG {
	public function __construct( SettingsContainerInterface|QROptions|iterable $options, QRMatrix $matrix ) {
		if ( is_iterable( $options ) ) {
			$options = new QROptions( $options );
		}

		$options->drawCircularModules = true;
		$options->gdImageUseUpscale   = false;

		parent::__construct( $options, $matrix );
	}

	protected function module( int $x, int $y, int $M_TYPE ): void {
		if ( ! $this->matrix->isDark( $M_TYPE ) ) {
			return;
		}

		if ( $this->matrix->checkTypeIn( $x, $y, array( QRMatrix::M_FINDER_DARK, QRMatrix::M_FINDER_DOT ) ) ) {
			$this->finderMarker( $x, $y );

			return;
		}

		$this->roundedModule( $x, $y, $M_TYPE );
	}

	private function finderMarker( int $x, int $y ): void {
		$quietzone = $this->options->addQuietzone ? $this->options->quietzoneSize : 0;
		$last      = $this->matrix->moduleCount - $quietzone - 7;
		$markers   = array(
			array( $quietzone, $quietzone ),
			array( $last, $quietzone ),
			array( $quietzone, $last ),
		);

		foreach ( $markers as $marker ) {
			if ( $x === $marker[0] && $y === $marker[1] ) {
				$this->roundedRect( $x * $this->scale, $y * $this->scale, 7 * $this->scale, $this->getModuleValue( QRMatrix::M_FINDER_DARK ) );
				$this->roundedRect( ( $x + 1 ) * $this->scale, ( $y + 1 ) * $this->scale, 5 * $this->scale, $this->getModuleValue( QRMatrix::M_FINDER ) );
				$this->roundedRect( ( $x + 2 ) * $this->scale, ( $y + 2 ) * $this->scale, 3 * $this->scale, $this->getModuleValue( QRMatrix::M_FINDER_DOT ) );
			}
		}
	}

	private function roundedModule( int $x, int $y, int $M_TYPE ): void {
		$neighbours = $this->matrix->checkNeighbours( $x, $y );
		$x1         = $x * $this->scale;
		$y1         = $y * $this->scale;
		$x2         = ( $x + 1 ) * $this->scale;
		$y2         = ( $y + 1 ) * $this->scale;
		$rectsize   = (int) ( $this->scale / 2 );
		$color      = $this->getModuleValue( $M_TYPE );

		if ( $neighbours & ( 1 << 7 ) ) {
			imagefilledrectangle( $this->image, $x1, $y1, $x1 + $rectsize, $y1 + $rectsize, $color );
			imagefilledrectangle( $this->image, $x1, $y2 - $rectsize, $x1 + $rectsize, $y2, $color );
		}

		if ( $neighbours & ( 1 << 3 ) ) {
			imagefilledrectangle( $this->image, $x2 - $rectsize, $y1, $x2, $y1 + $rectsize, $color );
			imagefilledrectangle( $this->image, $x2 - $rectsize, $y2 - $rectsize, $x2, $y2, $color );
		}

		if ( $neighbours & ( 1 << 1 ) ) {
			imagefilledrectangle( $this->image, $x1, $y1, $x1 + $rectsize, $y1 + $rectsize, $color );
			imagefilledrectangle( $this->image, $x2 - $rectsize, $y1, $x2, $y1 + $rectsize, $color );
		}

		if ( $neighbours & ( 1 << 5 ) ) {
			imagefilledrectangle( $this->image, $x1, $y2 - $rectsize, $x1 + $rectsize, $y2, $color );
			imagefilledrectangle( $this->image, $x2 - $rectsize, $y2 - $rectsize, $x2, $y2, $color );
		}

		imagefilledellipse(
			$this->image,
			(int) ( $x1 + ( $this->scale / 2 ) ),
			(int) ( $y1 + ( $this->scale / 2 ) ),
			max( 1, $this->scale - 1 ),
			max( 1, $this->scale - 1 ),
			$color
		);
	}

	private function roundedRect( int $x, int $y, int $size, int $color ): void {
		$radius   = (int) max( 1, $size * 0.22 );
		$diameter = $radius * 2;
		$x2       = $x + $size;
		$y2       = $y + $size;

		imagefilledrectangle( $this->image, $x + $radius, $y, $x2 - $radius, $y2, $color );
		imagefilledrectangle( $this->image, $x, $y + $radius, $x2, $y2 - $radius, $color );
		imagefilledellipse( $this->image, $x + $radius, $y + $radius, $diameter, $diameter, $color );
		imagefilledellipse( $this->image, $x2 - $radius, $y + $radius, $diameter, $diameter, $color );
		imagefilledellipse( $this->image, $x + $radius, $y2 - $radius, $diameter, $diameter, $color );
		imagefilledellipse( $this->image, $x2 - $radius, $y2 - $radius, $diameter, $diameter, $color );
	}
}
