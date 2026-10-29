<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Master Data · Data Pekerja: the people of the company (roster) — name, position, divisi, phone,
 * and the STOCKWISE account they log in with, if any. Kept by the Accurate purge (settings data).
 */
class EmployeeController extends Controller
{
    /** GET /api/employees?search=&department_id=&is_active= */
    public function index(Request $request): JsonResponse
    {
        $q = Employee::query()->with('department:id,name', 'user:id,name,username')->orderBy('name');
        foreach (preg_split('/\s+/', $request->string('search')->trim()->value(), -1, PREG_SPLIT_NO_EMPTY) as $w) {
            $q->where(fn ($x) => $x->where('name', 'like', "%{$w}%")->orWhere('position', 'like', "%{$w}%")
                ->orWhere('phone_wa', 'like', "%{$w}%")->orWhere('phone_office', 'like', "%{$w}%")
                ->orWhereHas('department', fn ($d) => $d->where('name', 'like', "%{$w}%")));
        }
        $request->whenFilled('department_id', fn ($v) => $q->where('department_id', $v));
        $request->whenFilled('is_active', fn ($v) => $q->where('is_active', filter_var($v, FILTER_VALIDATE_BOOL)));

        return response()->json(['data' => $q->limit(2000)->get()->map(fn (Employee $e) => $this->row($e))]);
    }

    /** GET /api/employees/departments — the divisi to pick from. */
    public function departments(): JsonResponse
    {
        return response()->json(['data' => Department::query()->orderBy('name')->get(['id', 'name'])]);
    }

    /** GET /api/employees/users — STOCKWISE accounts to link a worker to (the one they log in with). */
    public function users(): JsonResponse
    {
        return response()->json(['data' => \App\Models\User::query()->orderBy('name')->get(['id', 'name', 'username'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->unique($data['name'], $data['department_id'] ?? null);
        $e = Employee::create($data + ['is_active' => true, 'needs_review' => false, 'source' => 'stockwise']);

        return response()->json(['data' => $this->row($e->load('department:id,name', 'user:id,name,username'))], 201);
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        $data = $this->validated($request, true);
        if (array_key_exists('name', $data) || array_key_exists('department_id', $data)) {
            $this->unique($data['name'] ?? $employee->name, array_key_exists('department_id', $data) ? $data['department_id'] : $employee->department_id, $employee->id);
        }
        $employee->update($data + ['needs_review' => false]);

        return response()->json(['data' => $this->row($employee->refresh()->load('department:id,name', 'user:id,name,username'))]);
    }

    private function validated(Request $request, bool $update = false): array
    {
        $s = $update ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$s, 'string', 'max:150'],
            'position' => ['sometimes', 'nullable', 'string', 'max:100'],
            'phone_wa' => ['sometimes', 'nullable', 'string', 'max:40'],
            'phone_office' => ['sometimes', 'nullable', 'string', 'max:40'],
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
            'user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id', Rule::unique('employees', 'user_id')->ignore($request->route('employee')?->id)],
            'is_active' => ['sometimes', 'boolean'],
        ], ['user_id.unique' => 'Akun ini sudah dipakai pekerja lain.']);
    }

    /** One person once per divisi (same rule as the table). */
    private function unique(string $name, ?int $departmentId, ?int $exceptId = null): void
    {
        $taken = Employee::where('name_normalized', Employee::normalize($name))->where('department_id', $departmentId)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => 'Pekerja dengan nama ini sudah ada di divisi tersebut.']);
        }
    }

    private function row(Employee $e): array
    {
        return [
            'id' => $e->id, 'name' => $e->name, 'position' => $e->position, 'phone_wa' => $e->phone_wa, 'phone_office' => $e->phone_office,
            'department_id' => $e->department_id, 'department' => $e->department?->name,
            'user_id' => $e->user_id, 'user' => $e->user ? ['id' => $e->user->id, 'name' => $e->user->name, 'username' => $e->user->username] : null,
            'is_active' => $e->is_active, 'needs_review' => $e->needs_review, 'source' => $e->source,
        ];
    }

    /** GET /api/employees/template — download Excel template for import. */
    public function template(): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Set headers
        $sheet->setCellValue('A1', 'NO');
        $sheet->setCellValue('B1', 'NAMA');
        $sheet->setCellValue('C1', 'DIVISI');
        $sheet->setCellValue('D1', 'NO WA');
        $sheet->setCellValue('E1', 'NO TLP KANTOR');

        // Style header row
        $headerRange = 'A1:E1';
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFE0E0E0');

        // Add example row
        $sheet->setCellValue('A2', '1');
        $sheet->setCellValue('B2', 'Contoh Nama Pekerja');
        $sheet->setCellValue('C2', 'Gudang');
        $sheet->setCellValue('D2', '6281234567890');
        $sheet->setCellValue('E2', '02112345678');

        // Auto-size columns
        foreach (range('A', 'E') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'template_data_pekerja.xlsx';
        $tempPath = storage_path('app/temp/'.$filename);
        if (! is_dir(dirname($tempPath))) {
            mkdir(dirname($tempPath), 0755, true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        return response()->download($tempPath, $filename)->deleteFileAfterSend(true);
    }

    /** POST /api/employees/import — import employees from Excel. */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:5120'],
        ]);

        $file = $request->file('file');
        $path = $file->storeAs('temp', 'employees_import_'.time().'.'.$file->getClientOriginalExtension());
        $fullPath = storage_path('app/'.$path);

        try {
            $spreadsheet = IOFactory::load($fullPath);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();

            // Skip header row
            array_shift($rows);

            $created = 0;
            $updated = 0;
            $errors = [];

            foreach ($rows as $index => $row) {
                $rowNum = $index + 2; // Excel row number (1-indexed + header)

                // Skip empty rows
                if (empty(array_filter($row))) {
                    continue;
                }

                $nama = trim($row[1] ?? '');
                $divisi = trim($row[2] ?? '');
                $noWa = trim($row[3] ?? '');
                $noTlpKantor = trim($row[4] ?? '');

                if (empty($nama)) {
                    $errors[] = "Baris {$rowNum}: Nama wajib diisi";
                    continue;
                }

                // Find department by name
                $department = null;
                if (! empty($divisi)) {
                    $department = Department::where('name', $divisi)->first();
                    if (! $department) {
                        $errors[] = "Baris {$rowNum}: Divisi '{$divisi}' tidak ditemukan";
                    }
                }

                // Find existing employee by name and department
                $employee = Employee::where('name_normalized', Employee::normalize($nama))
                    ->where('department_id', $department?->id)
                    ->first();

                $data = [
                    'name' => $nama,
                    'phone_wa' => $noWa ?: null,
                    'phone_office' => $noTlpKantor ?: null,
                    'department_id' => $department?->id,
                    'needs_review' => false,
                    'source' => 'import:manual',
                ];

                if ($employee) {
                    $employee->update($data);
                    $updated++;
                } else {
                    Employee::create($data + ['is_active' => true]);
                    $created++;
                }
            }

            Storage::delete($path);

            return response()->json([
                'data' => [
                    'created' => $created,
                    'updated' => $updated,
                    'errors' => $errors,
                ],
            ]);
        } catch (\Throwable $e) {
            Storage::delete($path);

            return response()->json(['message' => 'Gagal mengimpor: '.$e->getMessage()], 500);
        }
    }
}
