<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin();
$pageTitle  = 'Master Supplier';
$activePage = 'suppliers';
$pdo        = getPDO();
$user       = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole(['admin']);
    csrfCheck();
    $action  = $_POST['action'] ?? '';
    $code    = trim($_POST['code']    ?? '');
    $name    = trim($_POST['name']    ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $phone   = trim($_POST['phone']   ?? '');
    $address = trim($_POST['address'] ?? '');

    if ($action === 'add') {
        try {
            $pdo->prepare("INSERT INTO suppliers (code,name,contact,phone,address) VALUES (?,?,?,?,?)")
                ->execute([$code, $name, $contact, $phone, $address]);
            flash('sup', 'Supplier ditambahkan.');
        } catch (PDOException $e) {
            flash('sup', 'Kode supplier sudah digunakan.', 'error');
        }
    } elseif ($action === 'edit') {
        $id = (int)$_POST['id'];
        $pdo->prepare("UPDATE suppliers SET code=?,name=?,contact=?,phone=?,address=? WHERE id=?")
            ->execute([$code, $name, $contact, $phone, $address, $id]);
        flash('sup', 'Supplier diupdate.');
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $pdo->prepare("UPDATE suppliers SET is_active=0 WHERE id=?")->execute([$id]);
        flash('sup', 'Supplier dinonaktifkan.');
    }
    header('Location: index.php');
    exit;
}

$suppliers = $pdo->query("SELECT * FROM suppliers WHERE is_active=1 ORDER BY name")->fetchAll();
$navbarTitle = 'Master Supplier';
require_once __DIR__ . '/../includes/header.php';
?>

<?php $f = getFlash('sup');
if ($f): ?>
    <div class="alert alert-<?= $f['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible">
        <?= e($f['msg']) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="d-flex align-items-center justify-content-end mb-3">
    <?php if ($user['role'] === 'admin'): ?>
        <button class="btn btn-sm text-white" style="background:#9a031e" data-bs-toggle="modal" data-bs-target="#modalAdd">
            <i class="bi bi-plus-lg"></i> Tambah Supplier
        </button>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Nama</th>
                    <th>PIC</th>
                    <th>Telepon</th>
                    <th>Alamat</th><?php if ($user['role'] === 'admin'): ?><th>Aksi</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($suppliers as $s): ?>
                    <tr>
                        <td><code><?= e($s['code']) ?></code></td>
                        <td><?= e($s['name']) ?></td>
                        <td><?= e($s['contact'] ?? '-') ?></td>
                        <td><?= e($s['phone'] ?? '-') ?></td>
                        <td><small><?= e($s['address'] ?? '-') ?></small></td>
                        <?php if ($user['role'] === 'admin'): ?>
                            <td>
                                <button class="btn btn-sm btn-outline-primary py-0"
                                    onclick="editSup(<?= htmlspecialchars(json_encode($s), ENT_QUOTES) ?>)">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Nonaktifkan supplier ini?')"><?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($suppliers)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">Belum ada supplier</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Add -->
<div class="modal fade" id="modalAdd" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Tambah Supplier</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="add">
                <div class="modal-body row g-3">
                    <div class="col-4"><label class="form-label">Kode *</label><input type="text" name="code" class="form-control form-control-sm" required></div>
                    <div class="col-8"><label class="form-label">Nama *</label><input type="text" name="name" class="form-control form-control-sm" required></div>
                    <div class="col-6"><label class="form-label">PIC</label><input type="text" name="contact" class="form-control form-control-sm"></div>
                    <div class="col-6"><label class="form-label">Telepon</label><input type="text" name="phone" class="form-control form-control-sm"></div>
                    <div class="col-12"><label class="form-label">Alamat</label><textarea name="address" class="form-control form-control-sm" rows="2"></textarea></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-sm text-white" style="background:#9a031e">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit -->
<div class="modal fade" id="modalEdit" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Supplier</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="es_id">
                <div class="modal-body row g-3">
                    <div class="col-4"><label class="form-label">Kode</label><input type="text" name="code" id="es_code" class="form-control form-control-sm" required></div>
                    <div class="col-8"><label class="form-label">Nama</label><input type="text" name="name" id="es_name" class="form-control form-control-sm" required></div>
                    <div class="col-6"><label class="form-label">PIC</label><input type="text" name="contact" id="es_contact" class="form-control form-control-sm"></div>
                    <div class="col-6"><label class="form-label">Telepon</label><input type="text" name="phone" id="es_phone" class="form-control form-control-sm"></div>
                    <div class="col-12"><label class="form-label">Alamat</label><textarea name="address" id="es_address" class="form-control form-control-sm" rows="2"></textarea></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-sm text-white" style="background:#9a031e">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function editSup(s) {
        document.getElementById('es_id').value = s.id;
        document.getElementById('es_code').value = s.code;
        document.getElementById('es_name').value = s.name;
        document.getElementById('es_contact').value = s.contact || '';
        document.getElementById('es_phone').value = s.phone || '';
        document.getElementById('es_address').value = s.address || '';
        new bootstrap.Modal(document.getElementById('modalEdit')).show();
    }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>