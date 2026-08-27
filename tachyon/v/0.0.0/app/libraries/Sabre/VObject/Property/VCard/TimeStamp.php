<?php

namespace Sabre\VObject\Property\VCard;

use Sabre\VObject\DateTimeParser;
use Sabre\VObject\InvalidDataException;
use Sabre\VObject\Property\Text;
use Sabre\Xml;

/**
 * TimeStamp property.
 *
 * This object encodes TIMESTAMP values.
 *
 * @copyright Copyright (C) fruux GmbH (https://fruux.com/)
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
class TimeStamp extends Text
{
    /**
     * In case this is a multi-value property. This string will be used as a
     * delimiter.
     */
    public string $delimiter = '';

    /**
     * Returns the type of value.
     *
     * This corresponds to the VALUE= parameter. Every property also has a
     * 'default' valueType.
     */
    public function getValueType(): string
    {
        return 'TIMESTAMP';
    }

    /**
     * Returns the value, in the format it should be encoded for json.
     *
     * This method must always return an array.
     *
     * @throws InvalidDataException
     */
    public function getJsonValue(): array
    {
        $parts = DateTimeParser::parseVCardDateTime($this->getValue());

        $dateStr =
            $parts['year'].'-'.
            $parts['month'].'-'.
            $parts['date'].'T'.
            $parts['hour'].':'.
            $parts['minute'].':'.
            $parts['second'];

        // Timezone
        if (!is_null($parts['timezone'])) {
            $dateStr .= $parts['timezone'];
        }

        return [$dateStr];
    }

    /**
     * Sets the value as it appears in jCard.
     *
     * getJsonValue() writes the extended ISO form jCard requires, and without
     * this the extended string came straight back as the property value and was
     * serialized as REV:2026-08-27T09:49:51Z. vCard 4.0 wants the basic form
     * (RFC 6350 4.3.3), and ical.js, so Nextcloud Contacts, fails on the
     * extended one with "Could not extract integer".
     *
     * Separators are dropped from the date and time only. The timezone keeps its
     * sign, since stripping "-" from "-05:00" would destroy the offset.
     */
    public function setJsonValue(array $value): void
    {
        parent::setJsonValue(array_map(
            function ($item) {
                return is_string($item)
                    ? preg_replace_callback(
                        '/^(\d{4})-?(\d{2})-?(\d{2})T(\d{2}):?(\d{2}):?(\d{2})(.*)$/',
                        function ($m) {
                            return $m[1].$m[2].$m[3].'T'.$m[4].$m[5].$m[6].str_replace(':', '', $m[7]);
                        },
                        $item
                    )
                    : $item;
            },
            $value
        ));
    }

    /**
     * This method serializes only the value of a property. This is used to
     * create xCard or xCal documents.
     */
    protected function xmlSerializeValue(Xml\Writer $writer): void
    {
        // xCard is the only XML and JSON format that has the same date and time
        // format than vCard.
        $valueType = strtolower($this->getValueType());
        $writer->writeElement($valueType, $this->getValue());
    }
}
