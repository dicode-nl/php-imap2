<?php

/*
 * This file is part of the PHP IMAP2 package.
 *
 * (c) Francesco Bianco <bianco@javanile.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Javanile\Imap2;

class BodyStructure
{
    protected static $encodingNumber = [
        '7BIT' => 0,
        '8BIT' => 1,
        'BASE64' => 3,
        'QUOTED-PRINTABLE' => 4,
    ];

    public static function fromMessage($message)
    {
        return self::fromBodyStructure($message->bodystructure);
    }

    protected static function fromBodyStructure($structure)
    {
        $parts = [];
        $parameters = [];

        #file_put_contents('t3.json', json_encode($structure, JSON_PRETTY_PRINT));
        #die();

        if (isset($structure[0]) && is_scalar($structure[0]) && strtolower($structure[0]) == 'text') {
            return self::textStructure($structure);
        }

        if (isset($structure[0]) && is_scalar($structure[0]) && strtolower($structure[0]) == 'application') {
            return self::extractPart($structure);
        }
        
        if (isset($structure[1]) && is_scalar($structure[1]) && strtolower($structure[1]) == 'json') {
            return self::textStructure($structure);
        }
        
        $section = 'parts';
        $subType = 'ALTERNATIVE';

        foreach ($structure as $item) {
            if (is_scalar($item) && strtolower($item) == 'alternative') {
                $section = 'parameters';
                continue;
            }

            if (is_scalar($item) && strtolower($item) == 'mixed') {
                $subType = 'MIXED';
                $section = 'parameters';
                continue;
            }

            if (is_scalar($item) && strtolower($item) == 'related') {
                $subType = 'RELATED';
                $section = 'parameters';
                continue;
            }
            
            if (is_scalar($item) && strtolower($item) == 'signed') {
                $subType = 'SIGNED';
                $section = 'parameters';
                continue;
            }

            if (is_scalar($item) && strtolower($item) == '*') {
            	$subType = '*';
                $section = 'parameters';
                continue;
            }

            if ($section == 'parts') {
                $parts[] = self::extractPart($item);
            } elseif (is_array($item)) {
                $parameters = self::extractParameters($item, $parameters);
            }
        }

        return (object) [
            'type' => 1,
            'encoding' => 0,
            'ifsubtype' => 1,
            'subtype' => strtoupper($subType),
            'ifdescription' => 0,
            'ifid' => 0,
            'ifdisposition' => 0,
            'ifdparameters' => 0,
            'ifparameters' => 1,
            'parameters' => $parameters,
            'parts' => $parts,
        ];
    }

    protected static function extractPart($item)
    {
        if (is_scalar($item[2]) && strtolower($item[2]) == 'related') {
            return self::extractPartAsRelated($item);
        }

        if (is_scalar($item[2]) && strtolower($item[2]) == 'alternative') {
            return self::extractPartAsAlternative($item);
        }

        if (is_scalar($item[1]) && strtolower($item[1]) == 'rfc822') {
            return self::extractPartAsRfc822($item);
        }

        if ( ($relatedIndex = array_search("related", $item)) !== false ) {
        	return self::extractPartAsRelated($item, $relatedIndex);
        }

        $attribute = null;
        $parameters = [];

        
        if (! is_scalar($item[0]) && !is_array($item[2])) {

	        if (isset($item[0]) && is_scalar($item[0]) && strtolower($item[0]) == 'text') {
	            return self::textStructure($item);
	        }
	        
            return (object) [
	            'type' => 8,
	            'encoding' => self::getEncoding($item, 5),
	            'ifsubtype' => 1,
	            'subtype' => 'UNKNOWN',
	            'ifdescription' => 0,
	            'ifid' => 0,
	            'ifdisposition' => 0,
	            'ifdparameters' => 0,
	            'ifparameters' => 0,
	            'parameters' => (object)$parameters,
	        ];
        }

		if ( is_array($item[2]) )
	        foreach ($item[2] as $value) {
	            if (empty($attribute)) {
	                $attribute = [
	                    'attribute' => $value,
	                    'value' => null,
	                ];
	            } else {
	                $attribute['value'] = $value;
	                $parameters[] = (object) $attribute;
	                $attribute = null;
	            }
	        }

        $type = 0;
        $linesIndex = 7;
        $bytesIndex = 6;
        
        if (is_scalar($item[0]) && strtolower($item[0]) == 'application') {
            $type = 3;
            $linesIndex = 9;
        }
        if (is_scalar($item[0]) && strtolower($item[0]) == 'message') {
            $type = 2;
            $linesIndex = 9;
        }
        if (is_scalar($item[0]) && strtolower($item[0]) == 'image') {
            $type = 5;
            $linesIndex = 9;
        }
        if (is_scalar($item[0]) && strtolower($item[0]) == 'x-zip-compressed') {
            #var_dump($item);
            #die();
        }

        $part = (object) [
            'type' => $type,
            'encoding' => self::getEncoding($item, 5),
            'ifsubtype' => 1,
            'subtype' => strtoupper($item[1]),
            'ifdescription' => 0,
            'description' => null,
            'ifid' => 0,
            'id' => null,
            'lines' => intval($item[$linesIndex]),
            'bytes' => intval($item[$bytesIndex]),
            'ifdisposition' => 0,
            'disposition' => null,
            'ifdparameters' => 0,
            'dparameters' => null,
            'ifparameters' => count($parameters) ? 1 : 0,
            'parameters' => $parameters ?: (object)[],
        ];

        if ( ! $part->lines ) unset($part->lines);
        if ( ! $part->bytes ) unset($part->bytes);

        if ($item[3]) {
            $part->ifid = 1;
            $part->id = $item[3];
        } else {
            unset($part->id);
        }

        if ($item[4]) {
            $part->ifdescription = 1;
            $part->description = $item[4];
        } else {
            unset($part->description);
        }

        if ($type == 5 || $type == 3) {
            unset($part->lines);
        }

        $dispositionIndex = 9;
        if ($type == 2) {
            $dispositionIndex = 11;
        } elseif ($type == 5 || $type == 3) {
            $dispositionIndex = 8;
        }
        if (isset($item[$dispositionIndex][0])) {
            $attribute = null;
            $dispositionParameters = [];
            $part->disposition = $item[$dispositionIndex][0];
            if (isset($item[$dispositionIndex][1]) && is_array($item[$dispositionIndex][1])) {
                foreach ($item[$dispositionIndex][1] as $value) {
                    if (empty($attribute)) {
                        $attribute = [
                            'attribute' => $value,
                            'value' => null,
                        ];
                    } else {
                        $attribute['value'] = $value;
                        $dispositionParameters[] = (object)$attribute;
                        $attribute = null;
                    }
                }
            }
            if ( count($dispositionParameters) ) {
            	$part->dparameters = $dispositionParameters;
            	$part->ifdparameters = 1;
            }
            else {
	            unset($part->dparameters);
            }
            $part->ifdisposition = 1;
        } else {
            unset($part->disposition);
            unset($part->dparameters);
        }

        return self::processSubParts($item, $part);
    }

    protected static function extractPartAsRelated($item, $relatedIndex = 2)
    {
        $part = (object) [
            'type' => 1,
            'encoding' => self::getEncoding($item, $relatedIndex+2),
            'ifsubtype' => 1,
            'subtype' => 'RELATED',
            'ifdescription' => 0,
            'ifid' => 0,
            'ifdisposition' => 0,
            'disposition' => null,
            'ifdparameters' => 0,
            'dparameters' => null,
            'ifparameters' => 1,
            'parameters' => [],
            'parts' => []
        ];

        $offsetIndex = 0;
        foreach ($item as $subPart) {
            if (!is_array($subPart)) {
                break;
            }
            $offsetIndex++;
            $part->parts[] = self::extractPart($subPart);
        }

        $part->parameters = self::extractParameters($item[$offsetIndex+1], []);

        unset($part->disposition);
        unset($part->dparameters);

        return $part;
    }

    protected static function extractPartAsAlternative($item)
    {
        $part = (object) [
            'type' => 1,
            'encoding' => self::getEncoding($item, 5),
            'ifsubtype' => 1,
            'subtype' => 'ALTERNATIVE',
            'ifdescription' => 0,
            'ifid' => 0,
            'ifdisposition' => 0,
            'disposition' => null,
            'ifdparameters' => 0,
            'dparameters' => null,
            'ifparameters' => 1,
            'parameters' => [],
            'parts' => []
        ];

        $offsetIndex = 0;
        foreach ($item as $subPart) {
            if (!is_array($subPart)) {
                break;
            }
            $offsetIndex++;
            $part->parts[] = self::extractPart($subPart);
        }

        $part->parameters = self::extractParameters($item[$offsetIndex+1], []);

        unset($part->disposition);
        unset($part->dparameters);

        return $part;
    }

    protected static function processSubParts($item, $part)
    {
        if (strtolower($item[0]) != 'message') {
            return $part;
        }

        $part->parts = [
            self::processSubPartAsMessage($item)
        ];

        return $part;
    }
    
    protected static function processSubPartAsMessage($item)
    {
        #file_put_contents('a3.json', json_encode($item, JSON_PRETTY_PRINT));

        if (isset($item[8][3]) && is_array($item[8][3])) {
            $parameters = self::extractParameters($item[8][3], []);
        } else {
            $parameters = self::extractParameters($item[8][4], []);
        }

        $message = (object) [
            'type' => 1,
            'encoding' => 0,
            'ifsubtype' => 1,
            'subtype' => 'MIXED',
            'ifdescription' => 0,
            'ifid' => 0,
            'ifdisposition' => 0,
            'ifdparameters' => 0,
            'ifparameters' => 1,
            'parameters' => $parameters,
            'parts' => []
        ];

        foreach ($item[8] as $itemPart) {
            if (isset($itemPart[2]) && is_scalar($itemPart[2]) && strtolower($itemPart[2]) == 'alternative') {
                $message->parts[] = self::extractPartAsAlternative($itemPart);
                continue;
            }

            if (empty($itemPart[2]) || !is_array($itemPart[2])) {
                continue;
            }

            $message->parts[] = self::extractSubPartFromMessage($itemPart);
        }

        return $message;
    }

    protected static function extractSubPartFromMessage($itemPart)
    {
        $parameters = self::extractParameters($itemPart[2], []);

        $type = 0;
        if (isset($itemPart[0]) && strtolower($itemPart[0]) == 'application') {
            $type = 3;
        }

        $part = (object) [
            'type' => $type,
            'encoding' => self::getEncoding($itemPart, 5),
            'ifsubtype' => 1,
            'subtype' => is_string($itemPart[1]) ? strtoupper($itemPart[1]) : 'PLAIN',
            'ifdescription' => 0,
            'description' => null,
            'ifid' => 0,
            'id' => null,
            'lines' => intval($itemPart[7]),
            'bytes' => intval($itemPart[6]),
            'ifdisposition' => 0,
            'disposition' => [],
            'ifdparameters' => 0,
            'dparameters' => [],
            'ifparameters' => 1,
            'parameters' => $parameters
        ];

        if ( ! $part->lines ) unset($part->lines);
        if ( ! $part->bytes ) unset($part->bytes);

        if ($itemPart[4]) {
            $part->ifdescription = 1;
            $part->description = $itemPart[4];
        } else {
            unset($part->description);
        }

        if ($type == 3) {
            unset($part->lines);
        }

        if ($itemPart[3]) {
            $part->ifid = 1;
            $part->id = $itemPart[3];
        } else {
            unset($part->id);
        }

        $dispositionParametersIndex = 9;
        if ($type == 3) {
            $dispositionParametersIndex = 8;
        }

        if (isset($itemPart[$dispositionParametersIndex][0])) {
            $attribute = null;
            $dispositionParameters = [];
            $part->disposition = $itemPart[$dispositionParametersIndex][0];
            if (isset($itemPart[$dispositionParametersIndex][1]) && is_array($itemPart[$dispositionParametersIndex][1])) {
                foreach ($itemPart[$dispositionParametersIndex][1] as $value) {
                    if (empty($attribute)) {
                        $attribute = [
                            'attribute' => $value,
                            'value' => null,
                        ];
                    } else {
                        $attribute['value'] = $value;
                        $dispositionParameters[] = (object) $attribute;
                        $attribute = null;
                    }
                }
            }
            $part->dparameters = $dispositionParameters;
            $part->ifdparameters = 1;
            $part->ifdisposition = 1;
        } else {
            unset($part->disposition);
            unset($part->dparameters);
        }

        return $part;
    }

    protected static function extractPartAsRfc822($item)
    {
        $parameters = self::extractParameters($item[2], []);

        $part = (object) [
            'type' => 2,
            'encoding' => self::getEncoding($item, 5),
            'ifsubtype' => 1,
            'subtype' => 'RFC822',
            'ifdescription' => 0,
            'ifid' => 0,
            'lines' => intval($item[9]),
            'bytes' => intval($item[6]),
            'ifdisposition' => 0,
            'disposition' => null,
            'ifdparameters' => 0,
            'dparameters' => null,
            'ifparameters' => $parameters ? 1 : 0,
            'parameters' => $parameters ?: (object) [] ,
            'parts' => [
                self::processSubPartAsMessage($item)
            ]
        ];

        $dispositionParametersIndex = 11;

        if (isset($item[$dispositionParametersIndex][0])) {
            $attribute = null;
            $dispositionParameters = [];
            $part->disposition = $item[$dispositionParametersIndex][0];
            if (isset($item[$dispositionParametersIndex][1]) && is_array($item[$dispositionParametersIndex][1])) {
                foreach ($item[$dispositionParametersIndex][1] as $value) {
                    if (empty($attribute)) {
                        $attribute = [
                            'attribute' => $value,
                            'value' => null,
                        ];
                    } else {
                        $attribute['value'] = $value;
                        $dispositionParameters[] = (object) $attribute;
                        $attribute = null;
                    }
                }
            }
            $part->dparameters = $dispositionParameters;
            $part->ifdparameters = 1;
            $part->ifdisposition = 1;
        } else {
            unset($part->disposition);
            unset($part->dparameters);
        }

        return $part;
    }

    protected static function extractPartAsPlain($itemPart)
    {
        $parameters = self::extractParameters($itemPart[2], []);

        $part = (object) [
            'type' => 0,
            'encoding' => self::getEncoding($itemPart, 5),
            'ifsubtype' => 1,
            'subtype' => 'PLAIN',
            'ifdescription' => 0,
            'ifid' => 0,
            'lines' => intval($itemPart[7]),
            'bytes' => intval($itemPart[6]),
            'ifdisposition' => 0,
            'disposition' => [],
            'ifdparameters' => 0,
            'dparameters' => [],
            'ifparameters' => 1,
            'parameters' => $parameters
        ];
        
        if ( ! $part->lines ) unset($part->lines);
        if ( ! $part->bytes ) unset($part->bytes);

        $dispositionParametersIndex = 9;

        if (isset($itemPart[$dispositionParametersIndex][0])) {
            $attribute = null;
            $dispositionParameters = [];
            $part->disposition = $itemPart[$dispositionParametersIndex][0];
            if (isset($itemPart[$dispositionParametersIndex][1]) && is_array($itemPart[$dispositionParametersIndex][1])) {
                foreach ($itemPart[$dispositionParametersIndex][1] as $value) {
                    if (empty($attribute)) {
                        $attribute = [
                            'attribute' => $value,
                            'value' => null,
                        ];
                    } else {
                        $attribute['value'] = $value;
                        $dispositionParameters[] = (object) $attribute;
                        $attribute = null;
                    }
                }
            }
            $part->dparameters = $dispositionParameters;
            $part->ifdparameters = 1;
            $part->ifdisposition = 1;
        } else {
            unset($part->disposition);
            unset($part->dparameters);
        }

        return $part;
    }

    protected static function extractParameters($attributes, $parameters)
    {
        if (empty($attributes)) {
            return [];
        }

        $attribute = null;

        foreach ($attributes as $value) {
            if (empty($attribute)) {
                $attribute = [
                    'attribute' => $value,
                    'value' => null,
                ];
            } else {
                $attribute['value'] = $value;
                $parameters[] = (object) $attribute;
                $attribute = null;
            }
        }

        return $parameters;
    }

    protected static function getEncoding($item, $encodingIndex)
    {
        return isset($item[$encodingIndex]) ? (self::$encodingNumber[strtoupper($item[$encodingIndex])] ?? 1) : 0;
    }

    protected static function textStructure($structure)
    {
        $parameters = self::extractParameters($structure[2], []);

        $type = 0;
        if (is_scalar($structure[0]) && strtolower($structure[0]) == 'application')
            $type = 3;

        $part = (object) [
            'type' => $type,
            'encoding' => self::getEncoding($structure, 5),
            'ifsubtype' => 1,
            'subtype' => strtoupper($structure[1]),
            'ifdescription' => 0,
            'ifid' => 0,
            'lines' => intval($structure[7]),
            'bytes' => intval($structure[6]),
            'ifdisposition' => 0,
            'ifdparameters' => 0,
            'ifparameters' => count($parameters),
            'parameters' => count($parameters) ? $parameters : (object) [],
        ];
        
        if ( $type == 3 ) {
        	unset($part->lines);
        }
        
        return $part;
    }
}
