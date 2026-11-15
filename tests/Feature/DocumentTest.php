<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use App\Services\DocumentService;
use Tests\TestCase;

final class DocumentTest extends TestCase
{
    private int $school;
    private int $teacher;
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->teacher = $this->createUser('t.teacher', [['subject_teacher', $this->school]]);
        $this->loginAs($this->teacher);
    }

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->tempFiles, 'is_file'));
        parent::tearDown();
    }

    private function upload(string $name, string $content, array $fields = [], int $error = UPLOAD_ERR_OK): \App\Core\Response
    {
        $tmp = tempnam(sys_get_temp_dir(), 'spms');
        file_put_contents($tmp, $content);
        $this->tempFiles[] = $tmp;
        return $this->post(
            '/documents',
            $fields + ['title' => 'מסמך בדיקה', 'category' => 'report', 'visibility' => 'restricted'],
            true,
            ['file' => ['name' => $name, 'tmp_name' => $tmp, 'error' => $error, 'size' => strlen($content)]]
        );
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    public function testValidUploadIsStoredOutsideTheWebRootUnderARandomName(): void
    {
        $this->assertRedirect('/documents', $this->upload('דוח שנתי.pdf', $this->pdf()));
        self::assertNull($this->session->pullFlash('errors'));

        $doc = App::db()->one('SELECT * FROM documents');
        self::assertSame('דוח שנתי.pdf', $doc['original_name']);
        self::assertSame('application/pdf', $doc['mime_type']);
        self::assertSame($this->school, (int) $doc['school_id']);
        self::assertSame($this->teacher, (int) $doc['uploaded_by']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $doc['stored_name']);
        self::assertSame(hash('sha256', $this->pdf()), $doc['sha256']);

        $path = App::root('storage/uploads/' . $doc['stored_name']);
        self::assertFileExists($path);
        self::assertStringNotContainsString(str_replace('\\', '/', App::root('public')), str_replace('\\', '/', $path));
        self::assertSame(1, $this->auditCount('create', 'document'));
    }

    public function testDownloadIsAnAuditedAttachment(): void
    {
        $this->upload('report.pdf', $this->pdf());
        $id = (int) App::db()->value('SELECT id FROM documents');

        $response = $this->get("/documents/$id/download");
        self::assertSame(200, $response->status);
        self::assertStringStartsWith('attachment;', $response->headers['Content-Disposition']);
        self::assertSame('application/pdf', $response->headers['Content-Type']);
        self::assertSame($this->pdf(), file_get_contents($response->filePath));
        self::assertSame(1, $this->auditCount('download', 'document'));
    }

    public function testDisguisedAndDisallowedFilesAreRejected(): void
    {
        $cases = [
            'shell.pdf' => '<?php echo "hello"; ?>',                   // script wearing a PDF extension
            'page.html' => '<html><script>alert(1)</script></html>',   // extension not allowed
            'tool.exe' => "MZ\x90\x00binary",
            'shell.php' => '<?php echo 1;',
            'double.php.pdf' => '<?php echo 1;',
            'noextension' => $this->pdf(),
            'image.png' => $this->pdf(),                               // content does not match extension
        ];
        foreach ($cases as $name => $content) {
            $this->assertRedirect('/documents', $this->upload($name, $content));
            self::assertArrayHasKey('file', $this->session->pullFlash('errors') ?? [], $name);
        }
        self::assertSame(0, (int) App::db()->value('SELECT COUNT(*) FROM documents'));
    }

    public function testOversizedMissingAndBrokenUploadsAreRejected(): void
    {
        $this->upload('big.pdf', $this->pdf(), [], UPLOAD_ERR_INI_SIZE);
        self::assertArrayHasKey('file', $this->session->pullFlash('errors'));
        $this->upload('partial.pdf', $this->pdf(), [], UPLOAD_ERR_PARTIAL);
        self::assertArrayHasKey('file', $this->session->pullFlash('errors'));
        $this->post('/documents', ['title' => 'x', 'category' => 'report', 'visibility' => 'staff']);
        self::assertArrayHasKey('file', $this->session->pullFlash('errors'));
        $this->upload('empty.pdf', '');
        self::assertArrayHasKey('file', $this->session->pullFlash('errors'));

        $this->upload('ok.pdf', $this->pdf(), ['title' => '', 'category' => 'nonsense', 'visibility' => 'public']);
        self::assertSame(['title', 'category', 'visibility'], array_keys($this->session->pullFlash('errors')));
        self::assertSame(0, (int) App::db()->value('SELECT COUNT(*) FROM documents'));
    }

    public function testRestrictedDocumentIsVisibleToItsUploaderOnly(): void
    {
        $this->upload('private.pdf', $this->pdf(), ['title' => 'Private plan', 'visibility' => 'restricted']);
        $this->upload('shared.pdf', $this->pdf(), ['title' => 'Shared plan', 'visibility' => 'staff']);
        $private = (int) App::db()->value("SELECT id FROM documents WHERE title = 'Private plan'");
        $shared = (int) App::db()->value("SELECT id FROM documents WHERE title = 'Shared plan'");

        $body = $this->get('/documents')->body;
        self::assertStringContainsString('Private plan', $body);
        self::assertStringContainsString('Shared plan', $body);

        // A colleague in the same school — even the principal — sees only the staff document.
        $this->loginAs($this->createUser('t.principal', [['principal', $this->school]]));
        $body = $this->get('/documents')->body;
        self::assertStringNotContainsString('Private plan', $body);
        self::assertStringContainsString('Shared plan', $body);
        self::assertSame(404, $this->get("/documents/$private/download")->status);
        self::assertSame(404, $this->post("/documents/$private/delete")->status);
        self::assertSame(200, $this->get("/documents/$shared/download")->status);
    }

    public function testLinkedEntityGrantsAccessOnlyThroughARegisteredChecker(): void
    {
        $this->upload('linked.pdf', $this->pdf(), ['title' => 'Linked doc']);
        $id = (int) App::db()->value('SELECT id FROM documents');
        $service = new DocumentService(App::db(), App::auth(), App::root('storage/uploads'));
        $service->link($id, 'test_entity', 7);
        $service->link($id, 'test_entity', 7); // idempotent
        self::assertSame(1, (int) App::db()->value('SELECT COUNT(*) FROM document_links'));

        $colleague = $this->createUser('t.colleague', [['subject_teacher', $this->school]]);
        $this->loginAs($colleague);
        self::assertSame(404, $this->get("/documents/$id/download")->status, 'A link without a checker grants nothing.');

        DocumentService::registerEntityChecker('test_entity', static fn (int $entityId, $auth) => $entityId === 7 && $auth->id() === $colleague);
        self::assertSame(200, $this->get("/documents/$id/download")->status);
        DocumentService::registerEntityChecker('test_entity', static fn () => false);
        self::assertSame(404, $this->get("/documents/$id/download")->status);
    }

    public function testDocumentsOfAnotherSchoolAreUnreachable(): void
    {
        $this->upload('staff.pdf', $this->pdf(), ['title' => 'School A staff doc', 'visibility' => 'staff']);
        $id = (int) App::db()->value('SELECT id FROM documents');

        $otherSchool = $this->createSchool();
        $this->loginAs($this->createUser('b.principal', [['principal', $otherSchool]]));
        self::assertStringNotContainsString('School A staff doc', $this->get('/documents')->body);
        self::assertSame(404, $this->get("/documents/$id/download")->status);
        self::assertSame(404, $this->post("/documents/$id/delete")->status);
        self::assertNull(App::db()->value('SELECT deleted_at FROM documents WHERE id = ?', [$id]));
    }

    public function testDeletionRules(): void
    {
        $this->upload('mine.pdf', $this->pdf(), ['title' => 'Mine', 'visibility' => 'staff']);
        $id = (int) App::db()->value('SELECT id FROM documents');

        // Another teacher can view a staff document but not delete it.
        $this->loginAs($this->createUser('t.other', [['subject_teacher', $this->school]]));
        self::assertSame(403, $this->post("/documents/$id/delete")->status);

        // A role with documents.delete can.
        $this->loginAs($this->createUser('t.coordinator', [['pedagogical_coordinator', $this->school]]));
        $this->assertRedirect('/documents', $this->post("/documents/$id/delete"));
        self::assertNotNull(App::db()->value('SELECT deleted_at FROM documents WHERE id = ?', [$id]));
        self::assertSame(404, $this->get("/documents/$id/download")->status, 'Soft-deleted documents are gone for everyone.');
        self::assertSame(1, $this->auditCount('delete', 'document'));
    }

    public function testViewerWithoutPermissionCannotUploadOrList(): void
    {
        $this->loginAs($this->createUser('t.viewer', [['viewer', $this->school]]));
        self::assertSame(403, $this->get('/documents')->status);
        self::assertSame(403, $this->upload('x.pdf', $this->pdf())->status);
    }
}
