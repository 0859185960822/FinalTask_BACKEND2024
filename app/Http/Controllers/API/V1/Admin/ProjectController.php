<?php

namespace App\Http\Controllers\API\V1\Admin;


use Exception;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Projects;
use App\Models\UsersHasTeam;
use Illuminate\Http\Request;
use App\Helpers\ResponseFormatter;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Http\Resources\projectResource;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Style\Border;
use Illuminate\Support\Facades\Response;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\ProjectExport;
use App\Traits\PaginationHelper;


use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Borders;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Font;

class ProjectController extends Controller
{
    /**
     * Project.Index
     *
     * @response array{data: ProjectResource[], meta: array{permissions: bool}}
     */
    use PaginationHelper;

    public function exportToExcel(Request $request)
    {
        try {
            // Ambil input dari request
            $judul_proyek = $request->input('title');
            $progress = $request->input('progress');
            $statusDeadline = $request->input('status_deadline');
            $sisaWaktu = $request->input('sisa_waktu');
            $deadlineFrom = $request->input('deadline_from');
            $deadlineTo = $request->input('deadline_to');

            // Query awal tanpa memfilter status_deadline
            $projectsQuery = Projects::with(['task', 'projectManager', 'teamMembers'])
                ->where('pm_id', Auth::user()->user_id);

            // Filter berdasarkan title (case insensitive)
            if ($judul_proyek) {
                $projectsQuery->whereRaw('LOWER(project_name) LIKE ?', ['%' . strtolower($judul_proyek) . '%']);
            }

            // if ($statusDeadline) {
            //     $projectsQuery->where(function ($query) use ($statusDeadline) {
            //         // Ambil status_deadline menggunakan ProjectResource tanpa filter koleksi
            //         $query->whereRaw("DATE(deadline) >= NOW()")
            //               ->when(strtolower($statusDeadline) === 'tepat waktu', function ($query) {
            //                   return $query->whereRaw("DATE(deadline) >= NOW()");
            //               })
            //               ->when(strtolower($statusDeadline) === 'terlambat', function ($query) {
            //                   return $query->whereRaw("DATE(deadline) < NOW()");
            //               });
            //     });
            // }

            // Filter berdasarkan progress
            if ($progress !== null) {
                $projectsQuery->whereHas('task', function ($query) use ($progress) {
                    $query->selectRaw('COUNT(*) as total_tasks, SUM(CASE WHEN status_task = "DONE" THEN 1 ELSE 0 END) as done_tasks')
                        ->groupBy('project_id')
                        ->havingRaw('ROUND((done_tasks / total_tasks) * 100) = ?', [$progress]);
                });
            }

            // Filter berdasarkan sisa waktu
            if ($sisaWaktu !== null) {
                $projectsQuery->whereHas('task', function ($query) use ($sisaWaktu) {
                    $query->selectRaw('project_id, DATEDIFF(deadline, NOW()) as remaining_days')
                        ->havingRaw('remaining_days = ?', [$sisaWaktu]);
                });
            }

            // Filter berdasarkan rentang tanggal deadline
            if ($deadlineFrom || $deadlineTo) {
                $projectsQuery->where(function ($query) use ($deadlineFrom, $deadlineTo) {
                    if ($deadlineFrom && $deadlineTo) {
                        $query->whereBetween('deadline', [$deadlineFrom, $deadlineTo]);
                    } elseif ($deadlineFrom) {
                        $query->where('deadline', '>=', $deadlineFrom);
                    } elseif ($deadlineTo) {
                        $query->where('deadline', '<=', $deadlineTo);
                    }
                });
            }

            // Ambil hasil dari query yang sudah difilter
            $projects = $projectsQuery->get();

            if ($statusDeadline) {
                $projects = $projects->filter(function ($project) use ($statusDeadline) {
                    // Ambil nilai status_deadline menggunakan ProjectResource
                    $resource = new ProjectResource($project);
                    $statusDeadlineProject = strtolower($resource->toArray(request())['status_deadline']);
                    
                    // Cocokkan status_deadline dengan statusDeadline yang diberikan (case-insensitive)
                    return $statusDeadlineProject === strtolower($statusDeadline);
                });
            }

            // Generate file Excel
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // Header kolom
            $sheet->setCellValue('A4', 'No');
            $sheet->setCellValue('B4', 'Nama Proyek');
            $sheet->setCellValue('C4', 'Progress %');
            $sheet->setCellValue('D4', 'Tanggal Deadline');
            $sheet->setCellValue('E4', 'Sisa Waktu');
            $sheet->setCellValue('F4', 'Status Deadline');

            // Styling header
            $headerStyle = [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0A0E32']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
            ];
            $sheet->getStyle('A4:F4')->applyFromArray($headerStyle);

            $row = 5;
            $no = 1;
            foreach ($projects as $project) {
                $totalTasks = $project->task->count();
                $doneTasks = $project->task->where('status_task', 'DONE')->count();
                $progress = $totalTasks > 0 ? round(($doneTasks / $totalTasks) * 100) : 0;
                $sisaWaktu = now()->diffInDays($project->deadline, false);
                // $statusDeadline = \Carbon\Carbon::parse($project->deadline)->isPast() ? 'Terlambat' : 'Tepat Waktu';
                $resource = new ProjectResource($project);
                $statusDeadline = $resource->toArray(request())['status_deadline'];
            

                // Isi data proyek
                $sheet->setCellValue('A' . $row, $no++);
                $sheet->setCellValue('B' . $row, $project->project_name);
                $sheet->setCellValue('C' . $row, $progress . '%');
                $sheet->setCellValue('D' . $row, \Carbon\Carbon::parse($project->deadline)->format('d/m/Y'));
                $sheet->setCellValue('E' . $row, $sisaWaktu . ' Hari');
                $sheet->setCellValue('F' . $row, $statusDeadline);

                $row++;
            }

            // Styling border
            $sheet->getStyle('A5:F' . ($row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle('A5:F' . ($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            // Atur lebar kolom
            foreach (['A' => 5, 'B' => 30, 'C' => 15, 'D' => 20, 'E' => 15, 'F' => 20] as $col => $width) {
                $sheet->getColumnDimension($col)->setWidth($width);
            }

            // Menambahkan judul
            $sheet->mergeCells('A2:F2');
            $sheet->setCellValue('A2', 'Laporan Project');
            $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(16);
            $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            // Download file
            $writer = new Xlsx($spreadsheet);
            $fileName = 'Laporan_Project_' . date('Ymd_His') . '.xlsx';

            return response()->streamDownload(function () use ($writer) {
                $writer->save('php://output');
            }, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function filterLaporanProject(Request $request)
    {
        try {
            $judul_proyek = $request->input('title');
            $progress = $request->input('progress');
            $statusDeadline = $request->input('status_deadline');
            $sisaWaktu = $request->input('sisa_waktu');
            $deadlineFrom = $request->input('deadline_from');
            $deadlineTo = $request->input('deadline_to');

            // Query awal tanpa memfilter status_deadline
            $projectsQuery = Projects::with(['task', 'projectManager', 'teamMembers'])
                ->where('pm_id', Auth::user()->user_id);

            // Filter berdasarkan title (case insensitive)
            if ($judul_proyek) {
                $projectsQuery->whereRaw('LOWER(project_name) LIKE ?', ['%' . strtolower($judul_proyek) . '%']);
            }

            // Ambil data awal dari database
            $projects = $projectsQuery->get();

            // Filter berdasarkan status_deadline
            if ($statusDeadline) {
                $projects = $projects->filter(function ($project) use ($statusDeadline) {
                    // Ambil nilai status_deadline dari ProjectResource
                    $resource = new ProjectResource($project);
                    $statusDeadlineProject = strtolower($resource->toArray(request())['status_deadline']);
            
                    // Cocokkan status_deadline (case-insensitive)
                    return $statusDeadlineProject === strtolower($statusDeadline);
                });
            }

            // Filter berdasarkan progress
            if ($progress !== null) {
                $projects = $projects->filter(function ($project) use ($progress) {
                    $totalTasks = $project->task->count();
                    $doneTasks = $project->task->where('status_task', 'DONE')->count();
                    $calculatedProgress = $totalTasks > 0 ? ($doneTasks / $totalTasks) * 100 : 0;

                    return round($calculatedProgress) == $progress;
                });
            }

            // Filter berdasarkan sisa waktu
            if ($sisaWaktu !== null) {
                $projects = $projects->filter(function ($project) use ($sisaWaktu) {
                    $remainingDays = now()->diffInDays($project->deadline, false);
                    return $remainingDays == $sisaWaktu;
                });
            }

            // Filter berdasarkan rentang deadline
            if ($deadlineFrom || $deadlineTo) {
                $projects = $projects->filter(function ($project) use ($deadlineFrom, $deadlineTo) {
                    $deadline = \Carbon\Carbon::parse($project->deadline);

                    if ($deadlineFrom && $deadlineTo) {
                        return $deadline->between($deadlineFrom, $deadlineTo);
                    }

                    if ($deadlineFrom) {
                        return $deadline->greaterThanOrEqualTo($deadlineFrom);
                    }

                    if ($deadlineTo) {
                        return $deadline->lessThanOrEqualTo($deadlineTo);
                    }

                    return true;
                });
            }

            // Return response
            return ResponseFormatter::success([
                'total_filtered_projects' => $projects->count(),
                'data_projects' => projectResource::collection($projects),
            ], 'Filtered Projects Retrieved Successfully');
        } catch (Exception $e) {
            return ResponseFormatter::error([
                'message' => 'Something went wrong',
                'error' => $e->getMessage(),
            ], 'Failed to filter projects', 500);
        }
    }

    public function index()
    {
        try {
            $user_id = Auth::user()->user_id;

            // Ambil role user login (asumsi relasi role sudah ada)
            $userRoles = Auth::user()->userRole->pluck('role_id'); // Sesuaikan dengan relasi role
            // dd($userRoles);

            $projects = Projects::with(['projectManager', 'teamMembers', 'task']);

            // Jika user adalah Project Manager
            if ($userRoles->contains(1)) { // Asumsi role_id = 1 adalah Project Manager
                $projects = $projects->where('pm_id', $user_id);
            }

            // Jika user adalah Collaborator
            if ($userRoles->contains(2)) { // Asumsi role_id = 2 adalah Collaborator
                $projects = $projects->orWhereHas('teamMembers', function ($query) use ($user_id) {
                    $query->where('users_id', $user_id);
                });
            }

            $project = $projects->get();

            $totalProject = $project->count();
            $onGoing = 0;
            $done = 0;

            foreach ($project as $proyek) {
                $totalTasks = $proyek->task->count();
                $doneTasks = $proyek->task->where('status_task', 'DONE')->count();

                if ($totalTasks > 0 && $doneTasks === $totalTasks) {
                    $done++;
                } else {
                    $onGoing++;
                }
            }

            return ResponseFormatter::success([
                'total_project' => $totalProject,
                'project_on_going' => $onGoing,
                'project_done' => $done,
                'data_project' => projectResource::collection($project)
            ], 'Success Get Data');
        } catch (Exception $e) {
            return ResponseFormatter::error([
                'message' => 'Something went wrong',
                'error' => $e,
            ], 'Failed to process data', 500);
        }
    }



    /**
     * Show the form for creating a new resource.
     */
    // public function create()
    // {
    //     //
    // }

    /**
     * Project.Store
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'project_name' => 'required',
                'description' => 'required',
                'deadline' => 'required',
            ]);

            if ($validator->fails()) {
                return ResponseFormatter::error([
                    'error' => $validator->errors()->all(),
                ], 'validation failed', 402);
            }

            $data = [
                'project_name' => $request->project_name,
                'description' => $request->description,
                'deadline' => $request->deadline,
                'pm_id' => Auth::user()->user_id,
                'created_by' => Auth::user()->user_id,
            ];
            $project = Projects::create($data);

            $project_id = $project->project_id;
            $data_collaborator = json_decode($request->collaborator);
            if ($data_collaborator) {
                $dataToInsert = [];
                foreach ($data_collaborator as $collaborators) {
                    $dataToInsert[] = [
                        'users_id' => $collaborators->kolaborator_data->user_id,
                        'project_id' => $project_id,
                        'created_at' => now(),
                    ];
                }
                UsersHasTeam::insert($dataToInsert); // Mass insert
            }

            return ResponseFormatter::success([
                $data,
            ], 'Success Create Data');
        } catch (Exception $error) {
            return ResponseFormatter::error([
                'message' => 'Something went wrong',
                'error' => $error,
            ], 'Failed to process data', 500);
        }
    }

    /**
     * Project.Detail
     */
    public function show(string $id)
    {
        try {
            $project = Projects::with([
                'projectManager',
                'teamMembers',
                'task' => function ($query) {
                    // Filter task hanya jika user bukan Administrator
                    if (!Auth::user()->userRole->pluck('role_id')->contains(1)) {
                        $user_id = Auth::user()->user_id;
                        $query->where('collaborator_id', $user_id);
                    }
                }
            ])
                ->find($id);

            return ResponseFormatter::success(new ProjectResource($project), 'Success Get Data');
        } catch (Exception $error) {
            return ResponseFormatter::error([
                'message' => 'Something went wrong',
                'error' => $error,
            ], 'Failed to process data', 500);
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    // public function edit(string $id)
    // {
    //     //
    // }

    /**
     * Project.Update
     */
    public function update(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'project_name' => 'required',
                'description' => 'required',
                'deadline' => 'required',
            ]);

            if ($validator->fails()) {
                return ResponseFormatter::error([
                    'error' => $validator->errors()->all(),
                ], 'validation failed', 402);
            }

            $data = Projects::where('project_id', $request->project_id)->first();
            if ($data) {
                $dataUpdate = [
                    'project_name' => $request->project_name,
                    'description' => $request->description,
                    'deadline' => $request->deadline,
                    'pm_id' => Auth::user()->user_id,
                    'updated_by' => Auth::user()->user_id,
                ];
                $data->update($dataUpdate);

                // Update kolaborator jika ada data collaborator
                $project_id = $data->project_id;

                if ($request->collaborator) {
                    $data_collaborator = json_decode($request->collaborator, true);
                    // Hapus kolaborator lama
                    UsersHasTeam::where('project_id', $project_id)->delete();

                    // Tambahkan kolaborator baru
                    $dataToInsert = [];
                    foreach ($data_collaborator as $collaborator) {
                        $dataToInsert[] = [
                            'users_id' => $collaborator,
                            'project_id' => $project_id,
                            'created_at' => Carbon::now(),
                            'updated_at' => Carbon::now(),
                        ];
                    }
                    UsersHasTeam::insert($dataToInsert); // Mass insert kolaborator baru
                }

                return ResponseFormatter::success([
                    $dataUpdate,
                ], 'Success Update Data');
            } else {
                return ResponseFormatter::error([], 'Data Not Found', 404);
            }
        } catch (Exception $error) {
            return ResponseFormatter::error([
                'message' => 'Something went wrong',
                'error' => $error,
            ], 'Failed to process data', 500);
        }
    }

    /**
     * Project.Delete
     */
    public function destroy($id)
    {
        $project = Projects::find($id);

        if ($project) {
            $project->delete();
            return ResponseFormatter::success(null, 'Project soft deleted successfully');
        } else {
            return ResponseFormatter::error([], 'Project not found', 404);
        }
    }

    public function addCollaborator(Request $request)
    {
        // Pastikan user_id dalam bentuk array
        // $user_ids = is_array($request->kolaborator_data->user_id) ? $request->kolaborator_data->user_id : json_decode($request->kolaborator_data->user_id, true);
        $user_ids = json_decode($request->user_id);
        // dd($user_ids);
        // Validasi data input
        $validator = Validator::make($request->all(), [
            'project_id' => 'required|exists:projects,project_id',
            'user_id' => 'required',
            'user_id.*' => 'exists:users,user_id', // Validasi setiap user_id yang ada di dalam array
        ]);

        if ($validator->fails()) {
            return ResponseFormatter::error([
                'error' => $validator->errors()->all(),
            ], 'Validation failed', 402);
        }

        // Cek apakah user sudah terdaftar dalam project menggunakan whereIn
        $exists = UsersHasTeam::where('project_id', $request->project_id)
            ->whereIn('users_id', $user_ids) // Menggunakan whereIn untuk memeriksa array user_id
            ->exists();

        if ($exists) {
            return ResponseFormatter::error([
                'error' => 'User sudah terdaftar dalam project.',
            ], 'Conflict', 409);
        }
        // UsersHasTeam::where('project_id', $request->project_id)
        //     ->whereIn('users_id', $user_ids)
        //     ->delete();

        // Prepare data untuk mass insert
        $newCollaborator = [];
        foreach ($user_ids as $user_id) {
            $newCollaborator[] = [
                'users_id' => $user_id,
                'project_id' => $request->project_id,
                'created_at' => Carbon::now(),
            ];
        }

        // Mass insert kolaborator baru
        UsersHasTeam::insert($newCollaborator);

        return response()->json([
            'message' => 'Collaborator berhasil ditambahkan ke project.',
            'added_user' => $newCollaborator,
        ], 201);
    }

    public function projectManagement(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 5);

            // Validasi perPage
            $perPageOptions = [5, 10, 15, 20, 50];
            if (!in_array($perPage, $perPageOptions)) {
                $perPage = 5;
            }

            $user_id = Auth::user()->user_id;

            // Ambil role user login (asumsi relasi role sudah ada)
            $userRoles = Auth::user()->userRole->pluck('role_id'); // Sesuaikan dengan relasi role
            // dd($userRoles);

            $projects = Projects::with(['projectManager', 'teamMembers']);

            // Jika user adalah Project Manager
            if ($userRoles->contains(1)) { // Asumsi role_id = 1 adalah Project Manager
                $projects = $projects->where('pm_id', $user_id);
            }

            // Jika user adalah Collaborator
            if ($userRoles->contains(2)) { // Asumsi role_id = 2 adalah Collaborator
                $projects = $projects->orWhereHas('teamMembers', function ($query) use ($user_id) {
                    $query->where('users_id', $user_id);
                });
            }

            // Eksekusi query
            $project = $projects->latest()->paginate($perPage);

            // Cek jika data kosong
            if ($project->isEmpty()) {
                return ResponseFormatter::error([], 'Project not found', 404);
            }

            // Return response
            return ResponseFormatter::success([
                'data_project' => projectResource::collection($project),
                'pagination' => [
                    'total' => $project->total(),
                    'per_page' => $project->perPage(),
                    'current_page' => $project->currentPage(),
                    'from' => $project->firstItem(),
                    'links' => $this->linkCollection($project),
                    'to' => $project->lastItem(),
                    'links' => $project->linkCollection(),
                    'next_page_url' => $project->nextPageUrl(),
                    'prev_page_url' => $project->previousPageUrl(),
                ],
            ], 'Success Get Data');
        } catch (Exception $error) {
            return ResponseFormatter::error([
                'message' => 'Something went wrong',
                'error' => $error->getMessage(),
            ], 'Failed to process data', 500);
        }
    }

    public function SearchProjectManagement(Request $request)
    {
        // Ambil parameter pencarian global dari request
        $search = $request->input('search'); // Input pencarian global
        $user_id = auth()->user()->user_id;

        // Query awal untuk memfilter berdasarkan PM ID
        $query = Projects::where(function ($subQuery) use ($user_id, $search) {
            $subQuery->where('pm_id', $user_id)
                ->where(function ($innerQuery) use ($search) {
                    $innerQuery->where('project_name', 'LIKE', "%{$search}%")
                        ->orWhere('description', 'LIKE', "%{$search}%");

                    // Validasi jika input berupa tanggal yang valid
                    if ($this->isValidDate($search)) {
                        $innerQuery->orWhereDate('deadline', Carbon::parse($search)->toDateString());
                    }
                    // // Filter berdasarkan hari sebelum deadline jika input adalah angka
                    // if (is_numeric($search)) {
                    //     $innerQuery->orWhereRaw(
                    //         "EXTRACT(DAY FROM (deadline - CURRENT_DATE)) = ?",
                    //         [$search]
                    //     );
                    // }
                });
        });

        // Eksekusi query
        $project = $query->get();

        // Cek jika data kosong
        if ($project->isEmpty()) {
            return ResponseFormatter::error([], 'Project not found', 404);
        }

        // Return response
        return ResponseFormatter::success(projectResource::collection($project), 'Success Get Data');
    }

    private function isValidDate($date)
    {
        return strtotime($date) !== false;
    }

    public function laporanProject(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 5);

            // Validasi perPage
            $perPageOptions = [5, 10, 15, 20, 50];
            if (!in_array($perPage, $perPageOptions)) {
                $perPage = 5;
            }

            // Eksekusi query
            $project = Projects::latest()->paginate($perPage);

            // Cek jika data kosong
            if ($project->isEmpty()) {
                return ResponseFormatter::error([], 'Project not found', 404);
            }

            return ResponseFormatter::success([
                'data_project' => projectResource::collection($project),
                'pagination' => [
                    'total' => $project->total(),
                    'per_page' => $project->perPage(),
                    'current_page' => $project->currentPage(),
                    'from' => $project->firstItem(),
                    'links' => $project->linkCollection(),
                    'to' => $project->lastItem(),
                    'links' => $project->linkCollection(),
                    'next_page_url' => $project->nextPageUrl(),
                    'prev_page_url' => $project->previousPageUrl(),
                ],
            ], 'Success Get Data');
        } catch (Exception $error) {
            return ResponseFormatter::error([
                'message' => 'Something went wrong',
                'error' => $error->getMessage(),
            ], 'Failed to process data', 500);
        }
    }
}
