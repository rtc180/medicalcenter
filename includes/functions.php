<?php
declare(strict_types=1);

/** Flow stages, in order. Used for labels and the progress tracker. */
const STAGES = [
    'waiting_nurse'  => 'Waiting for nurse',
    'triage'         => 'Triage & vitals',
    'waiting_doctor' => 'Waiting for doctor',
    'consultation'   => 'Consultation',
    'disposition'    => 'Treatment decision',
    'billing'        => 'Billing & checkout',
    'departed'       => 'Departed',
];

function generate_patient_no(): string
{
    $year = date('Y');
    $n = (int)db()->query("SELECT COUNT(*) FROM patients")->fetchColumn() + 1;
    do {
        $no = sprintf('BOH-%s-%05d', $year, $n++);
        $st = db()->prepare('SELECT 1 FROM patients WHERE patient_no=?');
        $st->execute([$no]);
    } while ($st->fetch());
    return $no;
}

/** Start a visit = "Patient Arrives" for an already-registered patient. */
function open_visit(int $patientId, string $priority = 'routine'): int
{
    if (!in_array($priority, ['routine', 'urgent', 'emergency'], true)) {
        $priority = 'routine';
    }
    $st = db()->prepare("SELECT id FROM visits WHERE patient_id=? AND status<>'departed'");
    $st->execute([$patientId]);
    if ($row = $st->fetch()) {
        return (int)$row['id']; // already in the clinic - don't duplicate
    }
    db()->prepare('INSERT INTO visits (patient_id, priority, arrived_at, registered_by) VALUES (?,?,?,?)')
        ->execute([$patientId, $priority, date('Y-m-d H:i:s'), $_SESSION['user']['id'] ?? null]);
    return (int)db()->lastInsertId();
}

function set_status(int $visitId, string $status): void
{
    db()->prepare('UPDATE visits SET status=? WHERE id=?')->execute([$status, $visitId]);
}

function age_from_dob(string $dob): int
{
    return (new DateTime($dob))->diff(new DateTime('today'))->y;
}

function money(float|string|null $v): string
{
    return '$' . number_format((float)$v, 2);
}

function active_payment_methods(): array
{
    return db()->query('SELECT method_key, label FROM payment_methods WHERE is_active=1 ORDER BY sort_order, label')->fetchAll();
}

function active_medical_aid_types(): array
{
    return db()->query('SELECT name FROM medical_aid_types WHERE is_active=1 ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
}

function clinic_print_settings(): array
{
    static $settings = null;
    if ($settings === null) {
        $row = db()->query('SELECT clinic_name, address, phone, email, registration_no, footer_note FROM clinic_settings WHERE id=1')->fetch();
        $settings = $row ?: [
            'clinic_name' => APP_NAME,
            'address' => CLINIC_ADDRESS,
            'phone' => CLINIC_PHONE,
            'email' => CLINIC_EMAIL,
            'registration_no' => '',
            'footer_note' => '',
        ];
    }

    return $settings;
}

function clinic_public_site_url(): string
{
    $url = trim(CLINIC_PUBLIC_URL);
    if ($url === '') {
        return '';
    }

    $parts = parse_url($url);
    if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) ||
        empty($parts['host']) || isset($parts['query']) || isset($parts['fragment'])) {
        return '';
    }

    return rtrim($url, '/') . '/';
}

function badge(string $text, string $class = ''): string
{
    return '<span class="badge ' . e($class) . '">' . e($text) . '</span>';
}

/** Rebuild invoice totals from consultation fee + prescriptions + referrals. */
function rebuild_invoice(int $visitId): void
{
    $med = db()->prepare('SELECT COALESCE(SUM(quantity*unit_price),0) FROM prescriptions WHERE visit_id=?');
    $med->execute([$visitId]);
    $ref = db()->prepare('SELECT COALESCE(SUM(fee),0) FROM referrals WHERE visit_id=?');
    $ref->execute([$visitId]);
    $medT = (float)$med->fetchColumn();
    $refT = (float)$ref->fetchColumn();
    $total = CONSULTATION_FEE + $medT + $refT;

    $ex = db()->prepare('SELECT id FROM invoices WHERE visit_id=?');
    $ex->execute([$visitId]);
    if ($ex->fetch()) {
        db()->prepare("UPDATE invoices SET consultation_fee=?, medication_total=?, referral_total=?, total=? WHERE visit_id=? AND status='unpaid'")
            ->execute([CONSULTATION_FEE, $medT, $refT, $total, $visitId]);
    } else {
        db()->prepare('INSERT INTO invoices (visit_id, consultation_fee, medication_total, referral_total, total) VALUES (?,?,?,?,?)')
            ->execute([$visitId, CONSULTATION_FEE, $medT, $refT, $total]);
    }
}
