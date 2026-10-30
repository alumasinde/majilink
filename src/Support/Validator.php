<?php
declare(strict_types=1);

namespace MajiLink\Support;

use MajiLink\Http\HttpException;

final class Validator
{
    private const WEAK_PASSWORDS = [
        'password', 'password1', '12345678', '123456789', 'qwertyui', 'letmein1',
        'iloveyou', 'admin123', 'welcome1', 'majilink', 'water123',
    ];

    /** @var array<string,string> */
    private array $errors = [];

    private function __construct(private readonly array $data, private readonly array $rules)
    {
    }

    /**
     * @throws HttpException when any rule fails
     */
    public static function validate(array $data, array $rules): array
    {
        $validator = new self($data, $rules);
        $validator->run();
        if ($validator->errors !== []) {
            throw HttpException::validation($validator->errors);
        }
        $clean = [];
        foreach ($rules as $field => $ruleString) {
            $value = $data[$field] ?? null;
            if ($value !== null) {
                $clean[$field] = $value;
            }
        }
        return $clean;
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $rules = explode('|', $ruleString);
            $value = $this->data[$field] ?? null;
            $present = $value !== null && $value !== '';
            $required = in_array('required', $rules, true);

            if (!$present) {
                if ($required) {
                    $this->errors[$field] = 'This field is required.';
                }
                continue;
            }

            $isNumeric = in_array('numeric', $rules, true) || in_array('integer', $rules, true);
            foreach ($rules as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                if ($name === 'required' || $name === 'nullable') {
                    continue;
                }
                $this->apply($field, $value, $name, $arg, $isNumeric);
                if (isset($this->errors[$field])) {
                    break;
                }
            }
        }
    }

    private function apply(string $field, mixed $value, string $name, ?string $arg, bool $isNumeric = false): void
    {
        $label = str_replace('_', ' ', $field);
        switch ($name) {
            case 'string':
                if (!is_string($value)) {
                    $this->errors[$field] = 'Must be text.';
                }
                break;
            case 'integer':
                if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                    $this->errors[$field] = 'Must be a whole number.';
                }
                break;
            case 'numeric':
                if (!is_numeric($value)) {
                    $this->errors[$field] = 'Must be a number.';
                }
                break;
            case 'bool':
                if (!in_array($value, [true, false, 0, 1, '0', '1'], true)) {
                    $this->errors[$field] = 'Must be true or false.';
                }
                break;
            case 'email':
                if (!is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    $this->errors[$field] = 'Must be a valid email address.';
                }
                break;
            case 'phone':
                if (!preg_match('/^(\+?254|0)[17]\d{8}$/', (string) $value)) {
                    $this->errors[$field] = 'Must be a Kenyan mobile number, e.g. 0712345678.';
                }
                break;
            case 'lat':
                if (!is_numeric($value) || (float) $value < -90 || (float) $value > 90) {
                    $this->errors[$field] = 'Must be a latitude between -90 and 90.';
                }
                break;
            case 'lng':
                if (!is_numeric($value) || (float) $value < -180 || (float) $value > 180) {
                    $this->errors[$field] = 'Must be a longitude between -180 and 180.';
                }
                break;
            case 'min':
                if ($isNumeric) {
                    if ((float) $value < (float) $arg) {
                        $this->errors[$field] = "Must be at least {$arg}.";
                    }
                } elseif (mb_strlen((string) $value) < (int) $arg) {
                    $this->errors[$field] = "Must be at least {$arg} characters.";
                }
                break;
            case 'max':
                if ($isNumeric) {
                    if ((float) $value > (float) $arg) {
                        $this->errors[$field] = "Must not be more than {$arg}.";
                    }
                } elseif (mb_strlen((string) $value) > (int) $arg) {
                    $this->errors[$field] = "Must not be more than {$arg} characters.";
                }
                break;
            case 'password':
                if (mb_strlen((string) $value) < 8) {
                    $this->errors[$field] = 'Password must be at least 8 characters.';
                } elseif (in_array(strtolower((string) $value), self::WEAK_PASSWORDS, true)) {
                    $this->errors[$field] = 'That password is too common. Choose another.';
                }
                break;
            case 'pin':
                if (!preg_match('/^\d{6}$/', (string) $value)) {
                    $this->errors[$field] = 'PIN must be exactly 6 digits.';
                } elseif (self::isTrivialPin((string) $value)) {
                    $this->errors[$field] = 'PIN is too easy to guess. Avoid repeats and sequences.';
                }
                break;
            case 'in':
                $options = explode(',', (string) $arg);
                if (!in_array((string) $value, $options, true)) {
                    $this->errors[$field] = 'Must be one of: ' . implode(', ', $options) . '.';
                }
                break;
            case 'time':
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $value)) {
                    $this->errors[$field] = 'Must be a time such as 08:30.';
                }
                break;
            case 'exists':
                [$table, $column] = array_pad(explode(',', (string) $arg), 2, 'id');
                if (Db::value("SELECT 1 FROM {$table} WHERE {$column} = ? LIMIT 1", [$value]) === null) {
                    $this->errors[$field] = "Selected {$label} does not exist.";
                }
                break;
            case 'unique':
                [$table, $column, $exceptId] = array_pad(explode(',', (string) $arg), 3, null);
                $sql = "SELECT 1 FROM {$table} WHERE {$column} = ?";
                $params = [$value];
                if ($exceptId !== null) {
                    $sql .= ' AND id <> ?';
                    $params[] = $exceptId;
                }
                if (Db::value($sql . ' LIMIT 1', $params) !== null) {
                    $this->errors[$field] = "This {$label} is already taken.";
                }
                break;
        }
    }


    public static function isTrivialPin(string $pin): bool
    {
        if (preg_match('/^(\d)\1{5}$/', $pin)) {
            return true;
        }
        $digits = array_map('intval', str_split($pin));
        $ascending = true;
        $descending = true;
        for ($i = 1; $i < 6; $i++) {
            if ($digits[$i] !== ($digits[$i - 1] + 1) % 10) {
                $ascending = false;
            }
            if ($digits[$i] !== ($digits[$i - 1] + 9) % 10) {
                $descending = false;
            }
        }
        return $ascending || $descending;
    }
}
