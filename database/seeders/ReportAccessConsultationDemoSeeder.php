<?php

namespace Database\Seeders;

use App\Models\Loan;
use App\Models\RequestStatusCatalog;
use App\Models\Transfer;
use App\Models\Unit;
use App\Models\User;
use App\Support\RequestNumberGenerator;
use Carbon\Carbon;
use Database\Seeders\Concerns\SeedsUserAccounts;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ReportAccessConsultationDemoSeeder extends Seeder
{
    use SeedsUserAccounts;

    private array $statusIds = [];

    private ?string $demoTransferCode = null;

    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            ProfileSeeder::class,
            ProfileRoleSeeder::class,
            EndpointRoleSeeder::class,
            RequestStatusCatalogSeeder::class,
        ]);
        $this->statusIds = RequestStatusCatalog::query()->pluck('id', 'code')->all();

        DB::transaction(function () {
            $this->deletePreviousDemoData();

            $units = $this->seedUnits();
            $users = $this->seedUsers($units);
            $documents = $this->seedArchiveDocuments($users['ugda'], $units);

            $this->seedLoans($users, $units, $documents);
        });
    }

    private function deletePreviousDemoData(): void
    {
        Loan::query()
            ->where(function ($query) {
                $query
                    ->where('number', 'like', 'RPT-LOAN-%')
                    ->orWhere('description', 'Solicitud de prestamo de prueba para reportes.');
            })
            ->get()
            ->each(function (Loan $loan) {
                $loan->dispatches()->each(fn ($dispatch) => $dispatch->items()->delete());
                $loan->returns()->each(fn ($return) => $return->items()->delete());
                $loan->dispatches()->delete();
                $loan->returns()->delete();
                $loan->events()->delete();
                $loan->documents()->delete();
                $loan->delete();
            });

        Transfer::query()
            ->where(function ($query) {
                $query
                    ->where('code', 'RPT-ACC-2026-001')
                    ->orWhere('description', 'Datos de prueba para reportes de consulta y acceso.');
            })
            ->get()
            ->each(function (Transfer $transfer) {
                $transfer->boxes->each(function ($box) {
                    $box->documents()->delete();
                });
                $transfer->events()->delete();
                $transfer->boxes()->delete();
                $transfer->delete();
            });
    }

    private function seedUnits(): array
    {
        $units = [
            'decanato' => Unit::withTrashed()->updateOrCreate(
                ['code' => 'RPT-DEC'],
                ['name' => 'Decanato', 'is_active' => true]
            ),
            'academica' => Unit::withTrashed()->updateOrCreate(
                ['code' => 'RPT-SA'],
                ['name' => 'Secretaria Academica', 'is_active' => true]
            ),
            'civil' => Unit::withTrashed()->updateOrCreate(
                ['code' => 'RPT-EIC'],
                ['name' => 'Escuela de Ingenieria Civil', 'is_active' => true]
            ),
            'arquitectura' => Unit::withTrashed()->updateOrCreate(
                ['code' => 'RPT-ARQ'],
                ['name' => 'Escuela de Arquitectura', 'is_active' => true]
            ),
        ];

        foreach ($units as $unit) {
            if ($unit->trashed()) {
                $unit->restore();
            }
        }

        return $units;
    }

    private function seedUsers(array $units): array
    {
        return [
            'ugda' => $this->seedUser('rpt.ugda@yopmail.com', 'RPT-UGDA', 'Carlos', 'Rodriguez', $units['decanato']->id, 'Usuario UGDA'),
            'maria' => $this->seedUser('rpt.maria@yopmail.com', 'RPT-MG', 'Maria', 'Gonzalez', $units['decanato']->id, 'Unidad Solicitante'),
            'juan' => $this->seedUser('rpt.juan@yopmail.com', 'RPT-JP', 'Juan', 'Perez', $units['decanato']->id, 'Unidad Solicitante'),
            'ana' => $this->seedUser('rpt.ana@yopmail.com', 'RPT-AM', 'Ana', 'Martinez', $units['academica']->id, 'Unidad Solicitante'),
            'carlos' => $this->seedUser('rpt.carlos@yopmail.com', 'RPT-CL', 'Carlos', 'Lopez', $units['civil']->id, 'Unidad Solicitante'),
            'laura' => $this->seedUser('rpt.laura@yopmail.com', 'RPT-LH', 'Laura', 'Hernandez', $units['arquitectura']->id, 'Unidad Solicitante'),
        ];
    }

    private function seedUser(string $email, string $carnet, string $firstName, string $lastName, int $unitId, string $profileName): User
    {
        return $this->seedUserWithProfileAndUnits(
            email: $email,
            password: 'password',
            personData: [
                'first_name' => $firstName,
                'second_name' => null,
                'first_last_name' => $lastName,
                'second_last_name' => null,
                'carnet' => $carnet,
            ],
            profileName: $profileName,
            unitIds: [$unitId],
        );
    }

    private function seedArchiveDocuments(User $ugdaUser, array $units): array
    {
        $requestDate = Carbon::parse('2026-04-28 08:00');
        $this->demoTransferCode = RequestNumberGenerator::transfer($requestDate);

        $transfer = Transfer::query()->create([
            'code' => $this->demoTransferCode,
            'user_id' => $ugdaUser->id,
            'unit_id' => $units['decanato']->id,
            'request_date' => $requestDate->toDateString(),
            'requested_at' => $requestDate,
            'status' => 'Aprobada',
            'authorization_status_id' => $this->statusIds['transfer_auth_authorized'] ?? null,
            'workflow_status_id' => $this->statusIds['transfer_status_transferred'] ?? null,
            'authorized_by_user_id' => $ugdaUser->id,
            'authorized_at' => Carbon::parse('2026-04-28 09:00'),
            'completed_by_user_id' => $ugdaUser->id,
            'completed_at' => Carbon::parse('2026-04-30 15:30'),
            'view_mode' => 'detail',
            'box_display_state' => 'expanded',
            'show_print_card' => true,
            'description' => 'Datos de prueba para reportes de consulta y acceso.',
        ]);

        $boxCode = RequestNumberGenerator::box(Carbon::parse('2026-04-30 16:00'));

        $box = $transfer->boxes()->create([
            'series_name' => 'Consultas y Prestamos',
            'start_year' => '2020',
            'end_year' => '2026',
            'box_number' => 1,
            'box_code' => $boxCode,
            'title' => 'Caja ' . $boxCode,
            'period_label' => '2020-2026',
            'location_code' => 'RPT-A1-E1-B1',
            'assigned_by_user_id' => $ugdaUser->id,
            'assigned_at' => Carbon::parse('2026-04-30 16:00'),
            'content_description' => 'Documentos disponibles para prestamos de prueba.',
        ]);

        $documents = [
            ['Acuerdos de Junta Directiva 2024', 'Actas y Reuniones', '2024'],
            ['Resoluciones Administrativas', 'Resoluciones', '2025'],
            ['Planes de Estudio', 'Planes y Programas', '2022'],
            ['Actas de Consejo', 'Actas y Reuniones', '2023'],
            ['Expedientes Estudiantiles 2022', 'Expedientes Academicos', '2022'],
            ['Contratos y Convenios 2021-2023', 'Contratos y Convenios', '2023'],
            ['Informes de Produccion Mensual', 'Informes y Reportes', '2025'],
            ['Memorandos Departamentales', 'Memorandums', '2024'],
            ['Circulares Internas', 'Circulares', '2024'],
            ['Analisis Estructurales de Edificios', 'Informes y Reportes', '2025'],
            ['Certificaciones Academicas', 'Certificaciones', '2026'],
            ['Ordenes de Compra', 'Documentos Contables', '2026'],
        ];

        $created = [];
        foreach ($documents as $index => [$title, $series, $year]) {
            $created[] = $box->documents()->create([
                'code' => 'RPT-DOC-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                'name' => $title,
                'series_label' => $series,
                'support_type' => $index % 3 === 0 ? 'Digital' : ($index % 3 === 1 ? 'Fisico' : 'Mixto'),
                'year_label' => $year,
                'pages_label' => (string) (20 + $index * 3),
                'sort_order' => $index + 1,
                'is_reserved' => false,
            ]);
        }

        return $created;
    }

    private function seedLoans(array $users, array $units, array $documents): void
    {
        $requesters = [
            [$users['maria'], $units['decanato']],
            [$users['juan'], $units['decanato']],
            [$users['ana'], $units['academica']],
            [$users['carlos'], $units['civil']],
            [$users['laura'], $units['arquitectura']],
        ];
        $statusCycle = [
            'loan_status_authorized',
            'loan_status_returned',
            'loan_status_observed',
            'loan_status_pending',
            'loan_status_authorized',
        ];
        $baseDates = array_merge(
            $this->dateRange('2026-05-02', 15),
            $this->dateRange('2026-06-03', 15)
        );

        foreach ($baseDates as $index => $date) {
            [$requester, $unit] = $requesters[$index % count($requesters)];
            $document = $documents[$index % count($documents)];
            $statusCode = $statusCycle[$index % count($statusCycle)];
            $requestedAt = Carbon::parse($date)->setTime(8 + ($index % 5), ($index * 7) % 60);
            $searchStartedAt = $requestedAt->copy()->addHours(2);
            $minutes = [25, 30, 35, 40, 45, 50, 55, 60, 70, 80][$index % 10];
            $searchCompletedAt = $searchStartedAt->copy()->addMinutes($minutes);

            $loan = Loan::query()->create([
                'number' => RequestNumberGenerator::loan($requestedAt),
                'user_id' => $requester->id,
                'unit_id' => $unit->id,
                'requested_at' => $requestedAt,
                'authorization_status_id' => $this->statusIds['loan_auth_authorized'] ?? null,
                'workflow_status_id' => $this->statusIds[$statusCode] ?? null,
                'search_status_id' => $this->statusIds['loan_search_completed'] ?? null,
                'authorized_by_user_id' => $users['ugda']->id,
                'authorized_at' => $requestedAt->copy()->addHour(),
                'ugda_authorized_by_user_id' => $users['ugda']->id,
                'ugda_authorized_at' => $requestedAt->copy()->addHour(),
                'search_started_by_user_id' => $users['ugda']->id,
                'search_started_at' => $searchStartedAt,
                'search_completed_by_user_id' => $users['ugda']->id,
                'search_completed_at' => $searchCompletedAt,
                'search_comments' => 'Dato de prueba para reportes dinamicos.',
                'view_mode' => 'detail',
                'description' => 'Solicitud de prestamo de prueba para reportes.',
            ]);

            $loanDocument = $loan->documents()->create([
                'document_kind' => 'system',
                'group_title' => 'Documentos del Sistema (1)',
                'title' => $document->name,
                'series_label' => $document->series_label,
                'box_code' => $box->boxCode(),
                'year_label' => $document->year_label,
                'unit_name_snapshot' => $unit->name,
                'document_type_label' => 'Copia',
                'document_type_tone' => 'info',
                'found_in_search' => true,
                'selected_for_loan' => true,
                'returned' => $statusCode === 'loan_status_returned',
                'sort_order' => 1,
            ]);

            $dispatch = $loan->dispatches()->create([
                'loan_date' => $searchCompletedAt->toDateString(),
                'due_date' => $searchCompletedAt->copy()->addDays(10)->toDateString(),
                'received_by_name' => $this->displayName($requester),
                'delivered_by_user_id' => $users['ugda']->id,
                'observations' => 'Entrega de prueba para reportes.',
            ]);
            $dispatch->items()->create(['loan_document_id' => $loanDocument->id]);

            if ($statusCode === 'loan_status_returned') {
                $return = $loan->returns()->create([
                    'return_date' => $searchCompletedAt->copy()->addDays(3)->toDateString(),
                    'received_by_user_id' => $users['ugda']->id,
                    'condition_label' => 'Devuelto en buen estado',
                    'observations' => 'Devolucion de prueba para reportes.',
                ]);
                $return->items()->create(['loan_document_id' => $loanDocument->id]);
            }
        }
    }

    private function dateRange(string $start, int $days): array
    {
        return collect(range(0, $days - 1))
            ->map(fn (int $offset) => Carbon::parse($start)->addDays($offset)->toDateString())
            ->all();
    }

    private function displayName(User $user): string
    {
        $user->loadMissing('person');

        return collect([
            $user->person?->first_name,
            $user->person?->first_last_name,
        ])->filter()->implode(' ') ?: $user->email;
    }
}
