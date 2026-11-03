<?php

declare(strict_types=1);

namespace Scrapfly;

/** Simulated vision deficiency of the Screenshot API. */
enum VisionDeficiency: string
{
    case Deuteranopia = 'deuteranopia';
    case Protanopia = 'protanopia';
    case Tritanopia = 'tritanopia';
    case Achromatopsia = 'achromatopsia';
    case ReducedContrast = 'reducedContrast';
    case BlurredVision = 'blurredVision';
}
