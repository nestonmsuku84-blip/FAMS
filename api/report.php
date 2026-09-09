<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireRole(['FAMS Officer', 'Administrator']);
$type = $_GET['type'] ?? '';

$reports = [
    'applications' => ['applications.csv', 'SELECT a.reference_number AS Reference, u.full_name AS Student, u.email AS Email, s.name AS Specialization, a.status AS Status, a.submitted_at AS Submitted FROM applications a JOIN student_profiles sp ON sp.id = a.student_id JOIN users u ON u.id = sp.user_id LEFT JOIN application_specializations aps ON aps.application_id = a.id AND aps.priority_order = 1 LEFT JOIN specializations s ON s.id = aps.specialization_id ORDER BY a.created_at DESC'],
    'students' => ['students.csv', 'SELECT u.full_name AS Student, u.email AS Email, sp.registration_number AS Registration_Number, i.name AS Institution, p.name AS Programme FROM student_profiles sp JOIN users u ON u.id = sp.user_id JOIN institutions i ON i.id = sp.institution_id JOIN programmes_of_study p ON p.id = sp.programme_id ORDER BY u.full_name'],
    'companies' => ['companies.csv', 'SELECT c.name AS Company, c.email AS Email, c.phone_number AS Phone, c.address AS Address, COUNT(p.id) AS Placements FROM companies c LEFT JOIN placements p ON p.company_id = c.id GROUP BY c.id ORDER BY c.name'],
    'placements' => ['placements.csv', 'SELECT a.reference_number AS Reference, u.full_name AS Student, c.name AS Company, cd.name AS Department, s.full_name AS Supervisor, p.placement_start_date AS Start_Date, p.placement_end_date AS End_Date, p.status AS Status FROM placements p JOIN applications a ON a.id = p.application_id JOIN student_profiles sp ON sp.id = a.student_id JOIN users u ON u.id = sp.user_id JOIN companies c ON c.id = p.company_id JOIN company_departments cd ON cd.id = p.company_department_id JOIN supervisors s ON s.id = p.supervisor_id ORDER BY p.assigned_at DESC'],
];
if (!isset($reports[$type])) { http_response_code(422); exit('Unknown report type.'); }

try {
    [$filename, $sql] = $reports[$type];
    $rows = db()->query($sql);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'wb');
    $headerWritten = false;
    while ($row = $rows->fetch()) {
        if (!$headerWritten) { fputcsv($output, array_keys($row)); $headerWritten = true; }
        fputcsv($output, $row);
    }
    if (!$headerWritten) fputcsv($output, ['No records found']);
    fclose($output);
} catch (Throwable $error) {
    http_response_code(500);
    exit('Unable to generate the report.');
}
