<?php
// pages/settings.php
require_once '../config/config.php';
require_once '../includes/functions.php';
require_once '../setup/migrations.php';

require_admin();

$message = '';
$messageType = '';

// Handle Updates
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action == 'update_company') {
        $company_name = sanitize_input($_POST['company_name']);
        $company_email = sanitize_input($_POST['company_email']);
        $address = sanitize_input($_POST['address']);
        $contact_info = sanitize_input($_POST['contact_info']);
        $vat_rate = floatval($_POST['vat_rate']);
        $tithe_rate = floatval($_POST['tithe_rate']);
        $education_tax_rate = floatval($_POST['education_tax_rate']);
        $invoice_terms = sanitize_input($_POST['invoice_terms']);
        $payment_instructions = sanitize_input($_POST['payment_instructions']);

        // Handle File Uploads (Logo & Signature)
        $logo_update_q = "";
        $sig_update_q = "";
        
        if (!empty($_FILES['logo']['name'])) {
            $logo_name = 'logo_' . time() . '_' . basename($_FILES['logo']['name']);
            if (!is_dir('../uploads/logos')) mkdir('../uploads/logos', 0777, true);
            $target_file = '../uploads/logos/' . $logo_name;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $target_file)) {
                $logo_update_q = ", logo_path = 'uploads/logos/$logo_name'";
            }
        }
        
        if (!empty($_FILES['signature']['name'])) {
            $sig_name = 'sig_' . time() . '_' . basename($_FILES['signature']['name']);
            if (!is_dir('../uploads/signatures')) mkdir('../uploads/signatures', 0777, true);
            $target_file = '../uploads/signatures/' . $sig_name;
            if (move_uploaded_file($_FILES['signature']['tmp_name'], $target_file)) {
                $sig_update_q = ", signature_path = 'uploads/signatures/$sig_name'";
            }
        }

        try {
            $sql = "UPDATE company_settings SET 
                    company_name = :cname, 
                    company_email = :cemail, 
                    address = :add, 
                    contact_info = :cinfo, 
                    vat_rate = :vrate, 
                    tithe_rate = :trate, 
                    education_tax_rate = :erate,
                    invoice_terms = :iterms,
                    payment_instructions = :pinst
                    $logo_update_q
                    $sig_update_q
                    WHERE id = 1";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':cname' => $company_name,
                ':cemail' => $company_email,
                ':add' => $address,
                ':cinfo' => $contact_info,
                ':vrate' => $vat_rate,
                ':trate' => $tithe_rate,
                ':erate' => $education_tax_rate,
                ':iterms' => $invoice_terms,
                ':pinst' => $payment_instructions
            ]);
            $message = "Company settings updated successfully.";
            $messageType = "success";
        } catch (PDOException $e) {
            $message = "Database error: " . $e->getMessage();
            $messageType = "danger";
        }
    } elseif ($action == 'add_project_type') {
        $stmt = $pdo->prepare("INSERT INTO project_types (name, code, description) VALUES (?, ?, ?)");
        $stmt->execute([$_POST['pt_name'], $_POST['pt_code'], $_POST['pt_desc']]);
        $message = "Project Type added successfully.";
        $messageType = "success";
    } elseif ($action == 'delete_project_type') {
        $stmt = $pdo->prepare("DELETE FROM project_types WHERE id = ?");
        $stmt->execute([$_POST['pt_id']]);
        $message = "Project Type deleted.";
        $messageType = "success";
    } elseif ($action == 'add_bank') {
        $stmt = $pdo->prepare("INSERT INTO bank_accounts (name, type, balance) VALUES (?, ?, ?)");
        $stmt->execute([$_POST['b_name'], $_POST['b_type'], $_POST['b_balance']]);
        $message = "Bank Account added successfully.";
        $messageType = "success";
    } elseif ($action == 'delete_bank') {
        $stmt = $pdo->prepare("DELETE FROM bank_accounts WHERE id = ?");
        $stmt->execute([$_POST['b_id']]);
        $message = "Bank Account deleted.";
        $messageType = "success";
    } elseif ($action == 'add_survey_field') {
        $stmt = $pdo->prepare("INSERT INTO project_survey_fields (project_type_id, field_name, field_label, field_type, is_required) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['project_type_id'], 
            sanitize_input($_POST['field_name']), 
            sanitize_input($_POST['field_label']), 
            $_POST['field_type'], 
            isset($_POST['is_required']) ? 1 : 0
        ]);
        $message = "Survey Field added successfully.";
        $messageType = "success";
    } elseif ($action == 'delete_survey_field') {
        $stmt = $pdo->prepare("DELETE FROM project_survey_fields WHERE id = ?");
        $stmt->execute([$_POST['f_id']]);
        $message = "Survey Field deleted.";
        $messageType = "success";
    } elseif ($action == 'repair_db') {
        $result = apply_v3_migrations($pdo);
        $message = $result['message'];
        $messageType = $result['success'] ? "success" : "danger";
    }
}

$settings = get_company_settings($pdo);
$project_types = $pdo->query("SELECT * FROM project_types ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$banks = $pdo->query("SELECT * FROM bank_accounts ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$survey_fields = [];
try {
    $survey_fields = $pdo->query("SELECT f.*, p.name as project_type_name FROM project_survey_fields f JOIN project_types p ON f.project_type_id = p.id ORDER BY p.name, f.field_label")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Table might not exist yet if migrations haven't run
}


include '../includes/header.php';
?>

<div class="flex-between mb-1">
    <h2><i class="fas fa-cog text-primary"></i> System Settings</h2>
</div>

<?php if ($message): ?>
    <div class="card" style="border-left: 4px solid var(--accent-<?= $messageType ?>); background-color: rgba(0,0,0,0.2);">
        <p class="text-<?= $messageType ?>" style="margin: 0; font-weight: 500;">
            <?= htmlspecialchars($message) ?>
        </p>
    </div>
<?php endif; ?>

<div class="card">
    <h4><i class="fas fa-building text-primary"></i> Company Branding & Defaults</h4>
    <hr style="border-color: var(--border-color); margin: 1rem 0;">
    
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="update_company">
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
            <div class="form-group">
                <label class="form-label">Company Name</label>
                <input type="text" name="company_name" class="form-control" value="<?= htmlspecialchars($settings['company_name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Contact Email</label>
                <input type="email" name="company_email" class="form-control" value="<?= htmlspecialchars($settings['company_email'] ?? '') ?>">
            </div>
            
            <div class="form-group">
                <label class="form-label">Address</label>
                <input type="text" name="address" class="form-control" value="<?= htmlspecialchars($settings['address'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Phone Line</label>
                <input type="text" name="contact_info" class="form-control" value="<?= htmlspecialchars($settings['contact_info'] ?? '') ?>">
            </div>
            
            <div class="form-group">
                <label class="form-label">System VAT Rate (%)</label>
                <input type="number" step="0.01" name="vat_rate" class="form-control" value="<?= htmlspecialchars($settings['vat_rate'] ?? '7.50') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Global Tithe Deduction Rate (%)</label>
                <input type="number" step="0.01" name="tithe_rate" class="form-control" value="<?= htmlspecialchars($settings['tithe_rate'] ?? '10.00') ?>">
            </div>
            
            <div class="form-group">
                <label class="form-label">Education Tax Rate (%)</label>
                <input type="number" step="0.01" name="education_tax_rate" class="form-control" value="<?= htmlspecialchars($settings['education_tax_rate'] ?? '3.00') ?>">
            </div>
            <div class="form-group" style="grid-column: span 2;">
                <label class="form-label">Invoice Default Payment Instructions</label>
                <textarea name="payment_instructions" class="form-control" rows="3"><?= htmlspecialchars($settings['payment_instructions'] ?? '') ?></textarea>
            </div>
            <div class="form-group" style="grid-column: span 2;">
                <label class="form-label">Invoice Terms & Conditions</label>
                <textarea name="invoice_terms" class="form-control" rows="2"><?= htmlspecialchars($settings['invoice_terms'] ?? '') ?></textarea>
            </div>

            <!-- File Uploads -->
            <div class="form-group">
                <label class="form-label">Company Logo</label>
                <?php if (!empty($settings['logo_path'])): ?>
                    <p style="font-size: 0.8rem; color: var(--accent-success);">Current: <?= htmlspecialchars($settings['logo_path']) ?></p>
                <?php endif; ?>
                <input type="file" name="logo" class="form-control" accept="image/*">
            </div>
            <div class="form-group">
                <label class="form-label">Authorized Signatory (Signature)</label>
                <?php if (!empty($settings['signature_path'])): ?>
                    <p style="font-size: 0.8rem; color: var(--accent-success);">Current: <?= htmlspecialchars($settings['signature_path']) ?></p>
                <?php endif; ?>
                <input type="file" name="signature" class="form-control" accept="image/*">
            </div>
        </div>

        <button type="submit" class="btn btn-primary" style="margin-top: 1rem;"><i class="fas fa-save"></i> Save Global Settings</button>
    </form>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-top: 2rem;">
    
    <!-- Project Types Management -->
    <div class="card">
        <h4><i class="fas fa-tags text-primary"></i> Project Types</h4>
        <hr style="border-color: var(--border-color); margin: 0.5rem 0 1rem 0;">
        
        <form method="POST" style="margin-bottom: 1rem; background: rgba(0,0,0,0.1); padding: 1rem; border-radius: 8px;">
            <input type="hidden" name="action" value="add_project_type">
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 0.5rem;">
                <input type="text" name="pt_name" class="form-control" placeholder="Project Name (e.g. IT Audit)" required>
                <input type="text" name="pt_code" class="form-control" placeholder="Code (e.g. AUD)" required>
            </div>
            <input type="text" name="pt_desc" class="form-control" placeholder="Description (Optional)" style="margin-top: 0.5rem;">
            <button class="btn btn-success" style="width: 100%; margin-top: 0.5rem;"><i class="fas fa-plus"></i> Add Project Type</button>
        </form>

        <div class="table-responsive">
            <table class="table" style="font-size: 0.8rem;">
                <tr><th>Name</th><th>Code</th><th>Action</th></tr>
                <?php foreach($project_types as $pt): ?>
                <tr>
                    <td><?= htmlspecialchars($pt['name']) ?></td>
                    <td><span style="background: var(--bg-main); padding: 2px 5px; border-radius: 3px;"><?= htmlspecialchars($pt['code']) ?></span></td>
                    <td>
                        <form method="POST" onsubmit="return confirm('Delete this project type?');">
                            <input type="hidden" name="action" value="delete_project_type">
                            <input type="hidden" name="pt_id" value="<?= $pt['id'] ?>">
                            <button class="btn btn-outline" style="padding: 0.1rem 0.4rem; color: var(--accent-danger);"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <!-- Bank Accounts Management -->
    <div class="card">
        <h4><i class="fas fa-university text-primary"></i> Payment Sources / Banks</h4>
        <hr style="border-color: var(--border-color); margin: 0.5rem 0 1rem 0;">
        
        <form method="POST" style="margin-bottom: 1rem; background: rgba(0,0,0,0.1); padding: 1rem; border-radius: 8px;">
            <input type="hidden" name="action" value="add_bank">
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 0.5rem;">
                <input type="text" name="b_name" class="form-control" placeholder="Account Name (e.g. GTBank Main)" required>
                <select name="b_type" class="form-control" required>
                    <option value="bank">Bank</option>
                    <option value="cash">Cash</option>
                    <option value="mobile_money">Mobile Money</option>
                </select>
            </div>
            <input type="number" step="0.01" name="b_balance" class="form-control" placeholder="Initial Balance (₦)" style="margin-top: 0.5rem;" required>
            <button class="btn btn-success" style="width: 100%; margin-top: 0.5rem;"><i class="fas fa-plus"></i> Add Account</button>
        </form>

        <div class="table-responsive">
            <table class="table" style="font-size: 0.8rem;">
                <tr><th>Account</th><th>Balance (₦)</th><th>Action</th></tr>
                <?php foreach($banks as $b): ?>
                <tr>
                    <td><?= htmlspecialchars($b['name']) ?></td>
                    <td style="color: var(--accent-success); font-weight: 600;"><?= number_format($b['balance'], 2) ?></td>
                    <td>
                        <form method="POST" onsubmit="return confirm('Delete this bank account? Warning: Transactions linked to it may lose context!');">
                            <input type="hidden" name="action" value="delete_bank">
                            <input type="hidden" name="b_id" value="<?= $b['id'] ?>">
                            <button class="btn btn-outline" style="padding: 0.1rem 0.4rem; color: var(--accent-danger);"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

</div>

<!-- Project Survey Fields Management (Full Width) -->
<div class="card" style="margin-top: 2rem;">
    <h4><i class="fas fa-clipboard-check text-primary"></i> Project Survey Fields</h4>
    <hr style="border-color: var(--border-color); margin: 0.5rem 0 1rem 0;">
    
    <form method="POST" style="margin-bottom: 1rem; background: rgba(0,0,0,0.1); padding: 1rem; border-radius: 8px;">
        <input type="hidden" name="action" value="add_survey_field">
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr auto; gap: 0.5rem; align-items: end;">
            <div class="form-group mb-0">
                <label style="font-size: 0.8rem;">Project Type</label>
                <select name="project_type_id" class="form-control" required>
                    <option value="">-- Select --</option>
                    <?php foreach($project_types as $pt): ?>
                        <option value="<?= $pt['id'] ?>"><?= htmlspecialchars($pt['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group mb-0">
                <label style="font-size: 0.8rem;">DB Field Name (no spaces)</label>
                <input type="text" name="field_name" class="form-control" placeholder="e.g. soil_type" required>
            </div>
            <div class="form-group mb-0">
                <label style="font-size: 0.8rem;">Display Label</label>
                <input type="text" name="field_label" class="form-control" placeholder="e.g. Soil Type" required>
            </div>
            <div class="form-group mb-0">
                <label style="font-size: 0.8rem;">Input Type</label>
                <select name="field_type" class="form-control" required>
                    <option value="text">Short Text</option>
                    <option value="textarea">Long Text</option>
                    <option value="number">Number</option>
                    <option value="date">Date</option>
                </select>
            </div>
            <div class="form-group mb-0" style="padding-bottom: 0.5rem;">
                <label style="display: flex; align-items: center; gap: 0.3rem; margin:0; font-size: 0.8rem;">
                    <input type="checkbox" name="is_required" value="1"> Required?
                </label>
            </div>
        </div>
        <button class="btn btn-success" style="width: 100%; margin-top: 1rem;"><i class="fas fa-plus"></i> Add Survey Field</button>
    </form>

    <div class="table-responsive">
        <table class="table" style="font-size: 0.8rem;">
            <tr><th>Project Type</th><th>Label</th><th>Field Name</th><th>Type</th><th>Required</th><th>Action</th></tr>
            <?php foreach($survey_fields as $sf): ?>
            <tr>
                <td><?= htmlspecialchars($sf['project_type_name']) ?></td>
                <td><?= htmlspecialchars($sf['field_label']) ?></td>
                <td><span style="background: var(--bg-main); padding: 2px 5px; border-radius: 3px;"><?= htmlspecialchars($sf['field_name']) ?></span></td>
                <td><?= htmlspecialchars(ucfirst($sf['field_type'])) ?></td>
                <td><?= $sf['is_required'] ? '<span class="text-danger">Yes</span>' : '<span class="text-muted">No</span>' ?></td>
                <td>
                    <form method="POST" onsubmit="return confirm('Delete this survey field? Existing responses will also be lost.');">
                        <input type="hidden" name="action" value="delete_survey_field">
                        <input type="hidden" name="f_id" value="<?= $sf['id'] ?>">
                        <button class="btn btn-outline" style="padding: 0.1rem 0.4rem; color: var(--accent-danger);"><i class="fas fa-trash"></i></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>

<!-- System Maintenance -->
<div class="card" style="margin-top: 2rem; border-top: 3px solid var(--accent-danger);">
    <h4 style="color: var(--accent-danger);"><i class="fas fa-tools"></i> System Maintenance</h4>
    <p class="text-muted" style="font-size: 0.85rem;">Use these tools only if you encounter system errors or after a major update.</p>
    <hr style="border-color: var(--border-color); margin: 1rem 0;">
    
    <div style="display: flex; align-items: center; gap: 1.5rem;">
        <div>
            <h5 style="margin: 0 0 0.25rem 0;">Repair & Sync Database</h5>
            <p style="font-size: 0.75rem; color: var(--text-muted); margin: 0;">Checks for missing database columns and synchronizes the schema to the latest version. Safe to run at any time.</p>
        </div>
        <form method="POST" style="margin-left: auto;">
            <input type="hidden" name="action" value="repair_db">
            <button type="submit" class="btn btn-outline" style="color: var(--accent-danger); border-color: var(--accent-danger);">
                <i class="fas fa-sync-alt"></i> Run Database Repair
            </button>
        </form>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
