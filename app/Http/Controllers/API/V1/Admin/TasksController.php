<?php

namespace App\Http\Controllers\API\V1\Admin;


use App\Models\Tasks;
use App\Http\Controllers\Controller;
use App\Helpers\ResponseFormatter;
use App\Models\User;
use Illuminate\Http\Request;
use Carbon\Carbon;


class TasksController extends Controller
{

    public function __construct()
    {
        $this->middleware('auth');
    }
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request) {}

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'task_name' => 'required|string|max:255',
                'priority_task' => 'required|in:0,1,2,3',
                'type_task' => 'required|in:MAJOR,MINOR',
                'collaborator_id' => 'required|exists:users,user_id',
                'project_id' => 'required|exists:projects,project_id',
                'deadline' => 'required|date'
            ]);

            $task = new Tasks();
            $task->task_name = $validated['task_name'];
            $task->priority_task = $validated['priority_task'];
            $task->type_task = $validated['type_task'];
            $task->collaborator_id = $validated['collaborator_id'];
            $task->project_id = $validated['project_id'];
            $task->deadline = $validated['deadline'];
            $task->status_task = 'PENDING';
            $task->created_at = now();
            $task->created_by = auth()->user()->user_id;

            $task->save();

            $deadlineDate = Carbon::parse($task->deadline);
            $now = Carbon::now();
            $remainingDays = $now->lessThanOrEqualTo($deadlineDate)
                ? $now->diffInDays($deadlineDate) + 1 . ' hari'
                : '0 hari';

            $deadlineStatus = $now->lessThanOrEqualTo($deadlineDate)
                ? 'tepat waktu'
                : 'terlambat';

            $task->sisa_waktu = $remainingDays;
            $task->deadline_status = $deadlineStatus;

            return ResponseFormatter::success($task, 'Task created successfully');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ResponseFormatter::error(
                $e->errors(),
                'Validation Error',
                422
            );
        } catch (\Exception $e) {
            return ResponseFormatter::error(
                ['error' => $e->getMessage()],
                'Failed to create task',
                500
            );
        }
    }


    public function getCollaborators()
    {
        try {
            $collaborators = User::where('status', 'ENABLE')
                ->select('user_id', 'name')
                ->orderBy('name', 'ASC')
                ->get();

            return ResponseFormatter::success(
                $collaborators,
                'Collaborators retrieved successfully'
            );
        } catch (\Exception $e) {
            return ResponseFormatter::error(
                ['error' => $e->getMessage()],
                'Failed to retrieve collaborators',
                500
            );
        }
    }


    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        try {
            $task = Tasks::with(['collaborator', 'project'])
                ->where('task_id', $id)
                ->first();

            if (!$task) {
                return ResponseFormatter::error(
                    null,
                    'Task not found',
                    404
                );
            }

            if ($task->deadline) {
                $deadlineDate = Carbon::parse($task->deadline);
                $now = Carbon::now();

                $remainingDays = $now->lessThanOrEqualTo($deadlineDate)
                    ? $now->diffInDays($deadlineDate) + 1 . ' hari'
                    : '0 hari';

                $deadlineStatus = $now->lessThanOrEqualTo($deadlineDate)
                    ? 'tepat waktu'
                    : 'terlambat';
            } else {
                $remainingDays = 'Tidak ada deadline';
                $deadlineStatus = 'Tidak ada deadline';
            }

            $task->sisa_waktu = $remainingDays;
            $task->deadline_status = $deadlineStatus;

            return ResponseFormatter::success(
                $task,
                'Task retrieved successfully'
            );
        } catch (\Exception $e) {
            return ResponseFormatter::error(
                ['error' => $e->getMessage()],
                'Failed to retrieve task',
                500
            );
        }
    }



    /**
     * Show the form for editing the specified resource.
     */
    public function edit($task_id, Request $request)
    {
        try {
            $task = Tasks::find($task_id);
            if (!$task) {
                return ResponseFormatter::error(
                    null,
                    'Task not found',
                    404
                );
            }

            $validated = $request->validate([
                'task_name' => 'required|string|max:255',
                'priority_task' => 'required|in:0,1,2,3',
                'type_task' => 'required|in:MAJOR,MINOR',
                'collaborator_id' => 'required|exists:users,user_id',
                'status_task' => 'nullable|in:PENDING,IN PROGRESS,DONE',
                'deadline' => 'nullable|date',
            ]);

            $task->task_name = $validated['task_name'];
            $task->priority_task = $validated['priority_task'];
            $task->type_task = $validated['type_task'];
            $task->collaborator_id = $validated['collaborator_id'];
            $task->status_task = $validated['status_task'] ?? 'PENDING';
            $task->deadline = $validated['deadline'] ?? $task->deadline; // Gunakan deadline lama jika tidak diubah
            $task->updated_at = now();
            $task->updated_by = auth()->user()->user_id;

            $task->save();

            if ($task->deadline) {
                $deadlineDate = Carbon::parse($task->deadline);
                $now = Carbon::now();

                $remainingDays = $now->lessThanOrEqualTo($deadlineDate)
                    ? $now->diffInDays($deadlineDate) + 1 . ' hari'
                    : '0 hari';

                $deadlineStatus = $now->lessThanOrEqualTo($deadlineDate)
                    ? 'tepat waktu'
                    : 'terlambat';
            } else {
                $remainingDays = 'Tidak ada deadline';
                $deadlineStatus = 'Tidak ada deadline';
            }

            $task->sisa_waktu = $remainingDays;
            $task->deadline_status = $deadlineStatus;

            return ResponseFormatter::success(
                $task,
                'Task updated successfully'
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ResponseFormatter::error(
                $e->errors(),
                'Validation Error',
                422
            );
        } catch (\Exception $e) {
            return ResponseFormatter::error(
                ['error' => $e->getMessage()],
                'Failed to update task',
                500
            );
        }
    }





    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        try {
            $task = Tasks::find($id);
            if (!$task) {
                return ResponseFormatter::error(
                    null,
                    'Task not found',
                    404
                );
            }

            $task->deleted_at = now();
            $task->updated_by = auth()->user()->user_id;
            $task->save();

            return ResponseFormatter::success(
                [
                    'task_id' => $task->task_id,
                    'task_name' => $task->task_name,
                    'status_task' => $task->status_task,
                    'deleted_at' => $task->deleted_at
                ],
                'Task deleted successfully'
            );
        } catch (\Exception $e) {
            return ResponseFormatter::error(
                ['error' => $e->getMessage()],
                'Failed to delete task',
                500
            );
        }
    }
}
