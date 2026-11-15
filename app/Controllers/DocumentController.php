<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\DocumentService;

final class DocumentController extends Controller
{
    private DocumentService $documents;

    public function __construct()
    {
        $this->documents = new DocumentService($this->db(), $this->auth(), App::root('storage/uploads'));
    }

    public function index(Request $request): Response
    {
        $rows = $this->documents->listVisible($this->schoolId());
        foreach ($rows as &$row) {
            $row['can_delete'] = $this->documents->canDelete($row);
        }
        return $this->view('documents/index', [
            'documents' => $rows,
            'categories' => App::config('upload.categories'),
            'extensions' => array_keys(App::config('upload.allowed')),
            'maxMb' => (int) (App::config('upload.max_bytes') / 1024 / 1024),
        ]);
    }

    public function store(Request $request): Response
    {
        $v = Validator::make($request->post, [
            'title' => 'required|string|max:190',
            'category' => 'required|in:' . implode(',', App::config('upload.categories')),
            'visibility' => 'required|in:restricted,staff',
        ], 'documents.fields');

        // Optional: attach the upload to a record (e.g. a work-plan goal) and return to its page.
        $entityType = $request->str('entity_type');
        $entityId = $request->int('entity_id');
        $back = '/documents';
        if ($entityType !== '') {
            if (!$this->documents->canAccessEntity($entityType, $entityId)) {
                throw new HttpException(403);
            }
            $back = preg_match('#^/[a-z-]+/\d+$#', $request->str('back')) ? $request->str('back') : $back;
        }

        $file = $request->file('file');
        $inspected = $this->documents->inspect($file);
        if (is_string($inspected)) {
            $v->addError('file', __($inspected, [
                'max' => (int) (App::config('upload.max_bytes') / 1024 / 1024),
            ]));
        }
        if ($v->fails()) {
            return $this->invalid($back, $v->errors(), $request->post);
        }

        [$extension, $mime] = $inspected;
        $documentId = $this->documents->store($file, $extension, $mime, $v->validated(), $this->schoolId());
        if ($entityType !== '') {
            $this->documents->link($documentId, $entityType, $entityId);
        }
        return $this->redirect($back, __('documents.uploaded'));
    }

    public function download(Request $request, int $id): Response
    {
        $document = $this->documents->findViewable($id, $this->schoolId());
        $path = $this->documents->path($document);
        $this->audit('download', 'document', $id);
        // Always served as an attachment with the type detected at upload time — never rendered inline.
        return Response::download($path, $document['original_name'], $document['mime_type']);
    }

    public function destroy(Request $request, int $id): Response
    {
        $document = $this->documents->findViewable($id, $this->schoolId());
        if (!$this->documents->canDelete($document)) {
            throw new HttpException(403);
        }
        $this->documents->delete($document);
        return $this->redirect('/documents', __('documents.deleted'));
    }
}
