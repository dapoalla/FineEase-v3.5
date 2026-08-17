<?php
require_once '../config/config.php';
require_once '../includes/functions.php';
require_login();

$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_survey') {
    try {
        $job_order_id = intval($_POST['job_order_id'] ?? 0);
        $project_type_id = intval($_POST['project_type_id'] ?? 0);
        $gps_lat = $_POST['gps_lat'] ?? null;
        $gps_lng = $_POST['gps_lng'] ?? null;
        $photoPath = null;

        // Handle photo upload
        if (!empty($_FILES['photo']['name'])) {
            $targetDir = '../uploads/survey_photos/';
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }
            $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            $fileName = uniqid('survey_') . '.' . $ext;
            $targetFile = $targetDir . $fileName;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $targetFile)) {
                $photoPath = 'uploads/survey_photos/' . $fileName;
            }
        }

        // Insert survey record
        $stmt = $pdo->prepare('INSERT INTO project_surveys (job_order_id, project_type_id, gps_lat, gps_lng, photo_path) VALUES (?,?,?,?,?)');
        $stmt->execute([$job_order_id, $project_type_id, $gps_lat, $gps_lng, $photoPath]);
        $survey_id = $pdo->lastInsertId();

        // Save dynamic field responses
        if (!empty($_POST['field'])) {
            $fieldStmt = $pdo->prepare('INSERT INTO project_survey_responses (survey_id, field_id, value) VALUES (?,?,?)');
            foreach ($_POST['field'] as $field_id => $value) {
                $fieldStmt->execute([$survey_id, $field_id, $value]);
            }
        }

        header("Location: project_survey.php?msg=Survey saved successfully");
        exit;
    } catch (PDOException $e) {
        $message = "Database error. Please ensure you've run the 'Repair & Sync Database' in Settings.";
        $messageType = "danger";
    }
}

if (isset($_GET['msg'])) {
    $message = sanitize_input($_GET['msg']);
    $messageType = 'success';
}

$view_mode = isset($_GET['action']) && $_GET['action'] == 'add' ? 'add' : 'list';

$jobs = [];
$projectTypes = [];
$surveys = [];

try {
    $jobs = $pdo->query("SELECT id, invoice_id, client_name FROM invoices ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    $projectTypes = $pdo->query("SELECT id, name FROM project_types WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $surveys = $pdo->query("
        SELECT ps.*, i.invoice_id, i.client_name, pt.name as project_type_name
        FROM project_surveys ps 
        JOIN invoices i ON ps.job_order_id = i.id 
        LEFT JOIN project_types pt ON ps.project_type_id = pt.id
        ORDER BY ps.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    if ($view_mode == 'list') {
        $message = "It looks like the Survey tables are missing. Please go to Settings and click 'Repair & Sync Database'.";
        $messageType = "danger";
    }
}

include '../includes/header.php';
?>
<div class="container">
    <div class="d-flex justify-content-between align-items-center mt-3 mb-3">
        <h2><i class="fas fa-clipboard-list text-primary"></i> Project Surveys</h2>
        <?php if ($view_mode === 'add'): ?>
            <a href="project_survey.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Back to Surveys</a>
        <?php else: ?>
            <a href="project_survey.php?action=add" class="btn btn-success"><i class="fas fa-plus"></i> Create Survey</a>
        <?php endif; ?>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($view_mode === 'list'): ?>
        
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th>Date</th>
                            <th>Job Order</th>
                            <th>Project Type</th>
                            <th>Has Photo?</th>
                            <th>GPS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($surveys)): ?>
                            <tr><td colspan="5" class="text-center text-muted">No surveys found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($surveys as $s): ?>
                                <tr>
                                    <td><?= date('d M, Y H:i', strtotime($s['created_at'])) ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($s['client_name']) ?></strong><br>
                                        <span class="text-muted" style="font-size: 0.85rem;"><?= htmlspecialchars($s['invoice_id']) ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($s['project_type_name'] ?? 'Unknown Type') ?></td>
                                    <td>
                                        <?php if ($s['photo_path']): ?>
                                            <a href="../<?= htmlspecialchars($s['photo_path']) ?>" target="_blank" class="badge badge-success"><i class="fas fa-image"></i> View Photo</a>
                                        <?php else: ?>
                                            <span class="text-muted">No photo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($s['gps_lat'] && $s['gps_lng']): ?>
                                            <a href="https://www.google.com/maps/search/?api=1&query=<?= $s['gps_lat'] ?>,<?= $s['gps_lng'] ?>" target="_blank" class="badge badge-info"><i class="fas fa-map-marker-alt"></i> Map</a>
                                        <?php else: ?>
                                            <span class="text-muted">None</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php else: ?>
        
        <div class="card p-4 mx-auto" style="max-width: 800px;">
            <form method="POST" enctype="multipart/form-data" id="surveyForm">
                <input type="hidden" name="action" value="save_survey" />
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Job Order</label>
                            <select name="job_order_id" class="form-control" required>
                                <option value="">-- Select Job Order --</option>
                                <?php foreach ($jobs as $j): ?>
                                    <option value="<?= $j['id'] ?>"><?= htmlspecialchars($j['client_name']) ?> (<?= htmlspecialchars($j['invoice_id']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Project Type</label>
                            <select name="project_type_id" id="projectTypeSelect" class="form-control" required>
                                <option value="">-- Select Project Type --</option>
                                <?php foreach ($projectTypes as $pt): ?>
                                    <option value="<?= $pt['id'] ?>"><?= htmlspecialchars($pt['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                
                <h5 class="mt-4 border-bottom pb-2">Dynamic Survey Fields</h5>
                <div id="dynamicFields" class="mb-4">
                    <div class="text-muted font-italic">Select a Project Type above to load associated fields.</div>
                </div>
                
                <h5 class="mt-4 border-bottom pb-2">Media & Location</h5>
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>GPS Location <small class="text-muted">(Optional)</small></label>
                            <div class="input-group">
                                <input type="text" name="gps_lat" id="gpsLat" class="form-control" placeholder="Lat" readonly />
                                <input type="text" name="gps_lng" id="gpsLng" class="form-control" placeholder="Lng" readonly />
                                <div class="input-group-append">
                                    <button type="button" class="btn btn-outline-primary" onclick="captureLocation()"><i class="fas fa-map-marker-alt"></i> Get</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Photo <small class="text-muted">(Optional)</small></label>
                            <input type="file" name="photo" accept="image/*" class="form-control-file border p-1 rounded" />
                        </div>
                    </div>
                </div>

                <hr class="mt-4 mb-4">
                <button type="submit" class="btn btn-success btn-lg btn-block"><i class="fas fa-save"></i> Save Survey Record</button>
            </form>
        </div>

    <?php endif; ?>
</div>

<script>
function captureLocation() {
    if (navigator.geolocation) {
        document.getElementById('gpsLat').value = "Loading...";
        document.getElementById('gpsLng').value = "Loading...";
        navigator.geolocation.getCurrentPosition(function(position) {
            document.getElementById('gpsLat').value = position.coords.latitude.toFixed(7);
            document.getElementById('gpsLng').value = position.coords.longitude.toFixed(7);
        }, function(err) {
            alert('Unable to retrieve location: ' + err.message);
            document.getElementById('gpsLat').value = "";
            document.getElementById('gpsLng').value = "";
        });
    } else {
        alert('Geolocation is not supported by this browser.');
    }
}

document.getElementById('projectTypeSelect')?.addEventListener('change', function() {
    const ptId = this.value;
    const container = document.getElementById('dynamicFields');
    container.innerHTML = '<div class="text-muted"><i class="fas fa-spinner fa-spin"></i> Loading fields...</div>';
    
    if (!ptId) {
        container.innerHTML = '<div class="text-muted font-italic">Select a Project Type above to load associated fields.</div>';
        return;
    }
    
    fetch('api_get_survey_fields.php?project_type_id=' + ptId)
        .then(res => res.json())
        .then(data => {
            container.innerHTML = '';
            if (data.error) {
                container.innerHTML = '<div class="alert alert-danger">' + data.error + '</div>';
                return;
            }
            if (!data.fields || data.fields.length === 0) {
                container.innerHTML = '<div class="text-muted">No custom fields defined for this project type.</div>';
                return;
            }
            
            data.fields.forEach(field => {
                const fieldDiv = document.createElement('div');
                fieldDiv.className = 'form-group';
                
                const label = document.createElement('label');
                label.textContent = field.field_label;
                if (field.is_required == 1) {
                    const reqAsterisk = document.createElement('span');
                    reqAsterisk.className = 'text-danger';
                    reqAsterisk.textContent = ' *';
                    label.appendChild(reqAsterisk);
                }
                
                fieldDiv.appendChild(label);

                if (field.field_type === 'textarea') {
                    const textarea = document.createElement('textarea');
                    textarea.name = 'field[' + field.id + ']';
                    textarea.className = 'form-control';
                    textarea.required = field.is_required == 1;
                    textarea.rows = 3;
                    fieldDiv.appendChild(textarea);
                } else {
                    const input = document.createElement('input');
                    input.name = 'field[' + field.id + ']';
                    input.className = 'form-control';
                    input.required = field.is_required == 1;
                    input.type = field.field_type; 
                    fieldDiv.appendChild(input);
                }
                container.appendChild(fieldDiv);
            });
        })
        .catch(err => {
            container.innerHTML = '<div class="alert alert-danger">Error loading fields. Please try again.</div>';
        });
});
</script>
<?php include '../includes/footer.php'; ?>
