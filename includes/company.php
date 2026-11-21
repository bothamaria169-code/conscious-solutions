<?php
// includes/company.php — Editable company details (overrides config/app.php constants)

require_once __DIR__ . '/../config/app.php';

function getCompanySettings(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $defaults = [
        'name'            => CS_NAME,
        'reg'             => CS_REG,
        'address'         => CS_ADDRESS,
        'email'           => CS_EMAIL,
        'phone'           => CS_PHONE,
        'hide_financials' => false,
    ];

    $path = __DIR__ . '/../config/company.json';
    if (file_exists($path)) {
        $data = json_decode(file_get_contents($path), true) ?: [];
        foreach ($defaults as $k => $_) {
            if (!empty($data[$k])) $defaults[$k] = $data[$k];
        }
    }

    return $cache = $defaults;
}

function saveCompanySettings(array $data): bool {
    $clean = [
        'name'            => trim($data['name']    ?? ''),
        'reg'             => trim($data['reg']     ?? ''),
        'address'         => trim($data['address'] ?? ''),
        'email'           => trim($data['email']   ?? ''),
        'phone'           => trim($data['phone']   ?? ''),
        'hide_financials' => !empty($data['hide_financials']),
    ];
    $path = __DIR__ . '/../config/company.json';
    return file_put_contents($path, json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
}

// True while "Demo Mode" is on — revenue totals should be masked instead of shown.
function financialsHidden(): bool {
    return !empty(getCompanySettings()['hide_financials']);
}

// Formats a Rand total, or masks it with a placeholder when Demo Mode is on.
function formatMoney(float $amount): string {
    return financialsHidden() ? 'R•••••' : 'R' . number_format($amount, 2);
}
