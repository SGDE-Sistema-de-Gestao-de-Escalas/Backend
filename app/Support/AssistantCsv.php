<?php

namespace App\Support;

use DateTime;
use Illuminate\Validation\ValidationException;

/**
 * Formato do CSV de assistentes: colunas, leitura tolerante (importação) e
 * escrita segura (exportação). Uma única definição das colunas serve a
 * exportação, o modelo vazio e a importação, para o ficheiro exportado poder
 * ser editado e importado de volta sem ajustes.
 */
class AssistantCsv
{
    /** Separador usado ao escrever. O Excel em português abre-o em colunas. */
    public const DELIMITER = ';';

    /**
     * Colunas, pela ordem do ficheiro. Os nomes são os mesmos campos da API.
     *
     * @var list<string>
     */
    public const COLUMNS = [
        'internal_number',
        'first_name',
        'last_name',
        'email',
        'phone',
        'nif',
        'social_security_number',
        'birth_date',
        'admission_date',
        'criminal_record_expiry',
        'address_street',
        'address_zip_code',
        'address_city',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_kinship',
        'available_for_transfer',
    ];

    /**
     * Colunas que têm de existir no cabeçalho de um ficheiro a importar.
     *
     * @var list<string>
     */
    public const REQUIRED_COLUMNS = ['internal_number', 'first_name', 'last_name', 'email'];

    public const DATE_COLUMNS = ['birth_date', 'admission_date', 'criminal_record_expiry'];

    /**
     * Protege uma célula contra "injeção de fórmulas": o Excel executa como
     * fórmula uma célula que comece por = + - @ (ou tabulação / retorno).
     * Os telefones (+351 912 345 678) e números negativos são texto
     * inofensivo e mantêm-se como estão.
     */
    public static function safeCell(mixed $value): string
    {
        $text = (string) ($value ?? '');

        if ($text === '') {
            return '';
        }

        $first = $text[0];

        $dangerous = in_array($first, ['=', '@', "\t", "\r"], true)
            || (in_array($first, ['+', '-'], true) && ! preg_match('/^[+-][0-9][0-9 ()\-]*$/', $text));

        return $dangerous ? "'".$text : $text;
    }

    /**
     * Desfaz o apóstrofo acrescentado por safeCell() quando um ficheiro
     * exportado volta a ser importado.
     */
    public static function unsafeCell(string $text): string
    {
        if (strlen($text) > 1 && $text[0] === "'" && in_array($text[1], ['=', '@', '+', '-', "\t", "\r"], true)) {
            return substr($text, 1);
        }

        return $text;
    }

    public static function boolToCell(bool $value): string
    {
        return $value ? 'sim' : 'não';
    }

    /**
     * sim/não, s/n, 1/0, true/false, yes/no. Devolve null se não reconhecer.
     */
    public static function parseBool(string $text): ?bool
    {
        return match (mb_strtolower(trim($text))) {
            'sim', 's', '1', 'true', 'verdadeiro', 'yes', 'y' => true,
            'não', 'nao', 'n', '0', 'false', 'falso', 'no' => false,
            default => null,
        };
    }

    /**
     * Converte uma data para AAAA-MM-DD. Aceita AAAA-MM-DD e DD/MM/AAAA,
     * DD-MM-AAAA e DD.MM.AAAA (o Excel em português reescreve as datas assim).
     * Devolve o texto original se não for uma data válida, para a validação
     * apontar o erro.
     */
    public static function normalizeDate(string $text): string
    {
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y'] as $format) {
            $date = DateTime::createFromFormat('!'.$format, $text);

            if ($date !== false && $date->format($format) === $text) {
                return $date->format('Y-m-d');
            }
        }

        return $text;
    }

    /**
     * Lê o conteúdo de um CSV. Aceita UTF-8 (com ou sem BOM) e Windows-1252
     * (o que o Excel grava em "CSV" simples), e os separadores ; , e tabulação.
     *
     * @return array{columns: list<string>, rows: array<int, array<string, ?string>>}
     *                                          `rows` tem como chave o número da linha no ficheiro (o cabeçalho é a 1).
     */
    public static function parse(string $content): array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $delimiter = self::detectDelimiter($content);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $header = fgetcsv($stream, null, $delimiter, '"', '');

        if ($header === false || $header === [null]) {
            fclose($stream);

            throw ValidationException::withMessages(['file' => 'O ficheiro está vazio.']);
        }

        $columns = array_map(fn ($name) => self::normalizeHeader((string) $name), $header);

        $rows = [];
        $line = 1;

        while (($record = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $line++;

            $values = array_map(fn ($cell) => trim((string) $cell), $record);

            if (implode('', $values) === '') {
                continue; // linha em branco
            }

            $row = [];

            foreach ($columns as $index => $name) {
                $cell = $values[$index] ?? '';
                $cell = self::unsafeCell($cell);
                $row[$name] = $cell === '' ? null : $cell;
            }

            $rows[$line] = $row;
        }

        fclose($stream);

        return ['columns' => $columns, 'rows' => $rows];
    }

    private static function normalizeHeader(string $name): string
    {
        $name = trim($name, " \t\n\r\0\x0B\"'");

        return str_replace([' ', '-'], '_', mb_strtolower($name));
    }

    private static function detectDelimiter(string $content): string
    {
        $firstLine = strtok($content, "\n") ?: '';

        $counts = [
            ';' => substr_count($firstLine, ';'),
            ',' => substr_count($firstLine, ','),
            "\t" => substr_count($firstLine, "\t"),
        ];

        arsort($counts);

        return reset($counts) > 0 ? array_key_first($counts) : self::DELIMITER;
    }
}
