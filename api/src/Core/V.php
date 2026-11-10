<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Input validation. Usage:
 *   $d = V::check($req->json(), ['name' => 'required|string|max:120', 'phone' => 'required|phone']);
 * Returns only declared keys, cast to proper types. Throws 422 with per-field messages.
 */
final class V
{
    public static function check(array $in, array $rules): array
    {
        $out = []; $errors = [];
        foreach ($rules as $field => $spec) {
            $rs = is_array($spec) ? $spec : explode('|', $spec);
            $present = array_key_exists($field, $in) && $in[$field] !== '' && $in[$field] !== null;
            $required = in_array('required', $rs, true);
            $nullable = in_array('nullable', $rs, true);
            if (!$present) {
                if ($required) $errors[$field] = 'This field is required';
                elseif (array_key_exists($field, $in) && $nullable) $out[$field] = null;
                continue;
            }
            $v = $in[$field];
            foreach ($rs as $r) {
                [$name, $arg] = array_pad(explode(':', $r, 2), 2, null);
                $err = null;
                switch ($name) {
                    case 'required': case 'nullable': break;
                    case 'string':
                        if (!is_string($v) && !is_numeric($v)) $err = 'Must be text';
                        else $v = trim((string)$v);
                        if (!$err && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $v)) $err = 'Contains invalid characters';
                        break;
                    case 'max': if (is_string($v) && mb_strlen($v) > (int)$arg) $err = "At most $arg characters"; elseif (is_numeric($v) && !is_string($v) && $v > (float)$arg) $err = "At most $arg"; break;
                    case 'min': if (is_string($v) && !is_numeric($v) && mb_strlen($v) < (int)$arg) $err = "At least $arg characters"; elseif (is_numeric($v) && (float)$v < (float)$arg) $err = "At least $arg"; break;
                    case 'int': if (filter_var($v, FILTER_VALIDATE_INT) === false) $err = 'Must be a whole number'; else $v = (int)$v; break;
                    case 'num': if (!is_numeric($v)) $err = 'Must be a number'; else $v = round((float)$v, 2); break;
                    case 'bool': $v = filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 1 : 0; break;
                    case 'email': if (!filter_var($v, FILTER_VALIDATE_EMAIL)) $err = 'Enter a valid email'; else $v = strtolower((string)$v); break;
                    case 'phone':
                        $digits = preg_replace('/\D/', '', (string)$v);
                        if (strlen($digits) === 12 && str_starts_with($digits, '91')) $digits = substr($digits, 2);
                        if (strlen($digits) === 11 && str_starts_with($digits, '0')) $digits = substr($digits, 1);
                        if (!preg_match('/^[6-9]\d{9}$/', $digits)) $err = 'Enter a valid 10-digit mobile number'; else $v = $digits;
                        break;
                    case 'date':
                        $d = \DateTime::createFromFormat('Y-m-d', (string)$v);
                        if (!$d || $d->format('Y-m-d') !== $v) $err = 'Use date format YYYY-MM-DD';
                        break;
                    case 'datetime':
                        $ts = strtotime((string)$v);
                        if ($ts === false) $err = 'Invalid date/time'; else $v = date('Y-m-d H:i:s', $ts);
                        break;
                    case 'time':
                        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string)$v)) $err = 'Use time format HH:MM'; else $v = strlen($v) === 5 ? $v . ':00' : $v;
                        break;
                    case 'in': if (!in_array((string)$v, explode(',', (string)$arg), true)) $err = 'Invalid choice'; break;
                    case 'url': if (!filter_var($v, FILTER_VALIDATE_URL) || !preg_match('#^https://#i', (string)$v)) $err = 'Enter a valid https:// link'; break;
                    case 'gstin': $v = strtoupper((string)$v); if (!preg_match('/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $v)) $err = 'Enter a valid 15-character GSTIN'; break;
                    case 'array': if (!is_array($v)) $err = 'Must be a list'; break;
                    default: throw new \LogicException("Unknown rule $name");
                }
                if ($err) { $errors[$field] = $err; break; }
            }
            if (!isset($errors[$field])) $out[$field] = $v;
        }
        if ($errors) throw HttpException::bad('Please fix the highlighted fields', $errors);
        return $out;
    }
}
