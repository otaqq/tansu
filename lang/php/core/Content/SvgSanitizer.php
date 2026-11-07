<?php

declare(strict_types=1);

namespace Otaqq\Tansu\Content;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use Otaqq\Tansu\TansuException;

/**
 * Keeps only an allowlist of SVG elements and attributes: no scripts, event handlers, foreignObject,
 * external references or DOCTYPE. Anything that does not parse is refused.
 */
final class SvgSanitizer
{
    private const NS = 'http://www.w3.org/2000/svg';

    private const XLINK = 'http://www.w3.org/1999/xlink';

    private const ELEMENTS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'metadata',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'textPath',
        'linearGradient', 'radialGradient', 'stop', 'clipPath', 'mask', 'pattern', 'marker',
        'filter', 'feBlend', 'feColorMatrix', 'feComponentTransfer', 'feComposite', 'feFlood',
        'feGaussianBlur', 'feMerge', 'feMergeNode', 'feMorphology', 'feOffset', 'feFuncA', 'feFuncB', 'feFuncG', 'feFuncR',
    ];

    private const ATTRIBUTES = [
        'id', 'class', 'style', 'transform', 'viewBox', 'preserveAspectRatio', 'width', 'height', 'x', 'y',
        'x1', 'x2', 'y1', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'fx', 'fy', 'd', 'points', 'pathLength',
        'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-opacity', 'stroke-linecap',
        'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'opacity',
        'color', 'display', 'visibility', 'clip-path', 'clip-rule', 'mask', 'filter', 'marker-start', 'marker-mid', 'marker-end',
        'font-family', 'font-size', 'font-weight', 'font-style', 'text-anchor', 'dominant-baseline', 'letter-spacing',
        'dx', 'dy', 'rotate', 'textLength', 'lengthAdjust', 'startOffset',
        'offset', 'stop-color', 'stop-opacity', 'gradientUnits', 'gradientTransform', 'spreadMethod',
        'clipPathUnits', 'maskUnits', 'maskContentUnits', 'patternUnits', 'patternContentUnits', 'patternTransform',
        'markerWidth', 'markerHeight', 'markerUnits', 'refX', 'refY', 'orient',
        'filterUnits', 'primitiveUnits', 'in', 'in2', 'result', 'stdDeviation', 'mode', 'type', 'values', 'operator',
        'k1', 'k2', 'k3', 'k4', 'flood-color', 'flood-opacity', 'radius', 'tableValues', 'slope', 'intercept',
        'amplitude', 'exponent', 'href', 'version',
    ];

    public function looksLikeSvg(string $bytes): bool
    {
        $head = substr($bytes, 0, 4096);
        $head = preg_replace('/^\xEF\xBB\xBF/', '', $head);

        return preg_match('/^\s*(<\?xml[^>]*\?>\s*)?(<!--.*?-->\s*)*<svg[\s>\/]/s', (string) $head) === 1;
    }

    /**
     * @throws TansuException type_not_allowed
     */
    public function sanitize(string $bytes): string
    {
        if (stripos($bytes, '<!DOCTYPE') !== false || stripos($bytes, '<!ENTITY') !== false) {
            throw TansuException::typeNotAllowed();
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($bytes, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $document->documentElement;
        if (!$loaded || $root === null || $root->localName !== 'svg' || $root->namespaceURI !== self::NS) {
            throw TansuException::typeNotAllowed();
        }
        $this->clean($root);

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n".$document->saveXML($root);
    }

    private function clean(DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            /** @var DOMAttr $attribute */
            if (!$this->allowedAttribute($attribute)) {
                $element->removeAttributeNode($attribute);
            }
        }
        foreach (iterator_to_array($element->childNodes) as $child) {
            /** @var DOMNode $child */
            if ($child instanceof DOMElement) {
                if ($child->namespaceURI === self::NS && in_array($child->localName, self::ELEMENTS, true)) {
                    $this->clean($child);
                } else {
                    $element->removeChild($child);
                }
            } elseif (!in_array($child->nodeType, [XML_TEXT_NODE, XML_CDATA_SECTION_NODE], true)) {
                $element->removeChild($child); // comments, processing instructions, entity references
            }
        }
    }

    private function allowedAttribute(DOMAttr $attribute): bool
    {
        $name = $attribute->localName;
        $namespace = $attribute->namespaceURI;
        if ($namespace !== null && !($namespace === self::XLINK && $name === 'href')) {
            return false;
        }
        if (!in_array($name, self::ATTRIBUTES, true)) {
            return false;
        }
        $value = $attribute->value;
        if ($name === 'href') {
            return str_starts_with($value, '#');
        }

        // url() may only point inside the document; no @import, no legacy script hooks
        if (preg_match('/url\s*\(\s*[\'"]?\s*(?!#)/i', $value) === 1
            || preg_match('/@import|expression\s*\(|javascript:|behavior\s*:/i', $value) === 1) {
            return false;
        }

        return true;
    }
}
