<?php

namespace frictionlessdata\tableschema;

use frictionlessdata\tableschema\Exceptions\DataSourceException;

/**
 * Class for working with Csv Dialect / RFC4180 conforming csv files.
 */
class CsvDialect
{
    /*
     * It doesn't handle all the functionality - but validates the dialect construct and parses csv rows according to the dialect
     *
     * the following requirements should be handled externally by the calling code
     * currently this class is focused on parsing a single row, so anything involving first / last rows
     * is not handled
     *
     * RFC4180:
     * - The last record in the file may or may not have an ending line break.
     * - There maybe an optional header line appearing as the first line of the file with the same format as normal record lines.
     *   This header will contain names corresponding to the fields in the file and should contain the same number of fields as the records in the rest of the file
     *   (the presence or absence of the header line should be indicated via the optional "header" parameter of this MIME type)
     * - Each line should contain the same number of fields throughout the file.
     *
     * Tabular Data requirements
     * - File encoding must be either UTF-8 (the default) or include encoding property
     * - If the CSV differs from this or the RFC in any other way regarding dialect
     *   (e.g. line terminators, quote charactors, field delimiters),
     *   the Tabular Data Resource MUST contain a dialect property describing its dialect.
     *   The dialect property MUST follow the CSV Dialect specification.
     */

    public $dialect;

    public function __construct($dialect = null)
    {
        $defaultDialect = [
            // specifies the character sequence which should separate fields (aka columns). Default = ,
            'delimiter' => ',',
            // specifies the character sequence which should terminate rows. Default = \r\n
            'lineTerminator' => "\r\n",
            // specifies a one-character string to use as the quoting character. Default = "
            'quoteChar' => '"',
            // controls the handling of quotes inside fields. If true, two consecutive quotes should be interpreted as one.
            // Default = true
            'doubleQuote' => true,
            // specifies a one-character string to use for escaping (for example, \), mutually exclusive with quoteChar.
            // Not set by default
            'escapeChar' => null,
            // specifies the null sequence (for example \N). Not set by default
            'nullSequence' => null,
            // specifies how to interpret whitespace which immediately follows a delimiter;
            // if false, it means that whitespace immediately after a delimiter should be treated as part of the following field.
            // Default = true
            'skipInitialSpace' => true,
            // indicates whether the file includes a header row. If true the first row in the file is a header row, not data.
            // Default = true
            'header' => true,
            // indicates that case in the header is meaningful. For example, columns CAT and Cat should not be equated.
            // Default = false
            'caseSensitiveHeader' => false,
            // a number, in n.n format, e.g., 1.0. If not present, consumers should assume latest schema version.
            'csvddfVersion' => null,
        ];
        if (null === $dialect) {
            $dialect = [];
        } else {
            $dialect = (array) $dialect;
        }
        $this->dialect = array_merge($defaultDialect, $dialect);
        if (!in_array($this->dialect['lineTerminator'], ["\r\n", "\n\r", "\n", "\r"])) {
            // we rely on PHP stream functions which make it a bit harder to support other line terminators
            // TODO: support custom lineTerminator
            throw new \Exception('custom lineTerminator is not supported');
        }
        if (1 != strlen($this->dialect['delimiter'])) {
            throw new \Exception('delimiter must be a single char');
        }
        if (null !== $this->dialect['nullSequence']) {
            throw new \Exception('custom nullSequence is not supported');
        }
    }

    /**
     * Parses the csv row according to the csv dialect.
     *
     * Returns an array of fields parsed form the line
     *
     * In case of line termination inside an enclosed field, the last field will contain a ContinueEnclosedField object
     *
     * @param $line string
     *
     * @return array
     *
     * @throws DataSourceException
     * @throws \Exception
     */
    public function parseRow($line, $continueLine = null)
    {
        // RFC4180      - Each record is located on a separate line, delimited by a line break (CRLF)
        // Tabular Data - The line terminator character MUST be LF or CRLF
        $line = rtrim($line, "\r\n");

        // RFC4180      - Within the header and each record, there may be one or more fields, separated by commas.
        //                Spaces are considered part of a field and should not be ignored.
        //                The last field in the record must not be followed by a comma.
        //              - Each field may or may not be enclosed in double quotes
        //                (however some programs, such as Microsoft Excel, do not use double quotes at all).
        //                If fields are not enclosed with double quotes, then double quotes may not appear inside the fields.
        //              - Fields containing line breaks (CRLF), double quotes, and commas
        //                should be enclosed in double-quotes.
        //              - If double-quotes are used to enclose fields,
        //                then a double-quote appearing inside a field must be escaped by preceding it with another double quote.
        $enclosed = null;
        $fields = [];
        $field = -1;
        $lastCharPos = mb_strlen($line) - 1;
        if ($continueLine) {
            if (!is_a($continueLine[count($continueLine) - 1], 'frictionlessdata\\tableschema\\ContinueEnclosedField')) {
                throw new \Exception('invalid continueLine');
            }
            unset($continueLine[count($continueLine) - 1]);
            $fields = $continueLine;
            $field = count($fields) - 1;
            $enclosed = true;
        }
        for ($charPos = 0; $charPos < mb_strlen($line); ++$charPos) {
            $char = mb_substr($line, $charPos, 1);
            if (null === $enclosed) {
                // start of a new field
                if ($char == $this->dialect['delimiter']) {
                    if (
                        // delimiter at end of line
                        ($charPos == $lastCharPos)
                        // double delimiters
                        || ($charPos != $lastCharPos && mb_substr($line, $charPos + 1, 1) == $this->dialect['delimiter'])
                    ) {
                        ++$field;
                        $fields[$field] = '';
                    }
                } else {
                    ++$field;
                    $fields[$field] = '';
                    if ($char == $this->dialect['quoteChar']) {
                        $enclosed = true;
                    } else {
                        $enclosed = false;
                        $fields[$field] .= $char;
                    }
                }
            } elseif ($enclosed) {
                // processing an enclosed field
                if (
                    null !== $this->dialect['doubleQuote'] && $char == $this->dialect['quoteChar']
                    && $charPos != $lastCharPos && mb_substr($line, $charPos + 1, 1) == $this->dialect['quoteChar']
                ) {
                    // doubleQuote mode is active, current char is a quote and next char is a quote
                    $fields[$field] .= $this->dialect['quoteChar'];
                    // skip a char
                    ++$charPos;
                    continue;
                } elseif (
                    $this->dialect['escapeChar'] && $char === $this->dialect['escapeChar']
                ) {
                    // encountered escape char, add the escaped char to the string
                    if ($charPos === $lastCharPos) {
                        throw new DataSourceException('Encountered escape char at end of line');
                    } else {
                        $fields[$field] .= mb_substr($line, $charPos + 1, 1);
                    }
                    // skip a char
                    ++$charPos;
                    continue;
                } elseif ($char == $this->dialect['quoteChar']) {
                    // encountered a quote signifying the end of the enclosed field
                    $enclosed = null;
                    continue;
                } else {
                    // character in enclosed field
                    $fields[$field] .= $char;
                    continue;
                }
            } else {
                // processing a non-enclosed field
                if ($char == $this->dialect['quoteChar']) {
                    // non enclosed field - cannot have a quotes
                    throw new \Exception('Invalid csv file - if field is not enclosed with double quotes - then double quotes may not appear inside the field');
                } elseif ($char == $this->dialect['delimiter']) {
                    // end of non-enclosed field + start of new field
                    if (
                        // delimiter at end of line
                        ($charPos == $lastCharPos)
                        // double delimiters
                        || ($charPos != $lastCharPos && mb_substr($line, $charPos + 1, 1) == $this->dialect['delimiter'])
                    ) {
                        ++$field;
                        $fields[$field] = '';
                    }
                    $enclosed = null;
                    continue;
                } else {
                    // character in non-enclosed field
                    $fields[$field] .= $char;
                    continue;
                }
            }
        }
        if ($this->dialect['skipInitialSpace']) {
            $fields = array_map(function ($field) {
                return ltrim($field);
            }, $fields);
        }
        if (true === $enclosed && !is_a($fields[count($fields) - 1], 'frictionlessdata\\tableschema\\ContinueEnclosedField')) {
            $fields[$field + 1] = new ContinueEnclosedField();
        }

        return $fields;
    }
}

class ContinueEnclosedField
{
}
