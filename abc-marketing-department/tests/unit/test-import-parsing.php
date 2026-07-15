<?php
/**
 * CSV number/date parsing tests.
 *
 * @package ABCMD
 */

use ABCMD\Import\CsvImporter;

// Numbers.
eq( 1234.56, CsvImporter::parse_number( '£1,234.56' ), 'GBP thousands + decimal' );
eq( 1234.56, CsvImporter::parse_number( '1.234,56' ), 'European format (dot thousands, comma decimal)' );
eq( 12.5, CsvImporter::parse_number( '12,50' ), 'Comma decimal only' );
eq( -42.0, CsvImporter::parse_number( '(42)' ), 'Parenthesised negative' );
eq( -5.0, CsvImporter::parse_number( '-5' ), 'Leading minus' );
eq( 1000.0, CsvImporter::parse_number( '1,000' ), 'Comma thousands only' );
eq( null, CsvImporter::parse_number( '' ), 'Empty string is null' );
eq( null, CsvImporter::parse_number( 'n/a' ), 'Non-numeric is null' );

// Dates.
eq( '2026-06-18', CsvImporter::parse_date( '2026-06-18', 'ymd' ), 'ISO date explicit' );
eq( '2026-06-18', CsvImporter::parse_date( '18/06/2026', 'dmy' ), 'DMY explicit' );
eq( '2026-06-18', CsvImporter::parse_date( '06/18/2026', 'mdy' ), 'MDY explicit' );
eq( '2026-06-18', CsvImporter::parse_date( '2026-06-18', 'auto' ), 'Auto detects ISO' );
eq( null, CsvImporter::parse_date( '', 'auto' ), 'Empty date is null' );
eq( null, CsvImporter::parse_date( 'not a date', 'auto' ), 'Garbage date is null' );
