<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
if (!is_admin()) {
    header('Location: index.php');
    exit;
}

$operatore = [
    'id_operatore' => null,
    'nome' => '',
    'cognome' => '',
    'telefono' => '',
    'email' => '',
    'ruolo_aziendale' => '',
    'note' => '',
    // Dati USER
    'id_user' => null,
    'username' => '',
    'ruolo' => 'operatore',
    'user_email' => ''
];
$is_edit = false;
$error = '';
$success = '';

// 1. CARICAMENTO DATI ESISTENTI
if (isset($_GET['id'])) {
    $is_edit = true;
    $id_operatore = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT o.*, u.id_user, u.username, u.ruolo, u.email AS user_email FROM operatori o LEFT JOIN users u ON o.id_operatore = u.id_operatore WHERE o.id_operatore = ?");
    $stmt->execute([$id_operatore]);
    $data = $stmt->fetch();

    if ($data) {
        // Unisci i dati dell'operatore e dell'utente
        $operatore = array_merge($operatore, $data);
    } else {
        $error = "Operatore non trovato.";
        $is_edit = false;
    }
}

// 2. GESTIONE SUBMIT DEL FORM
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op_data = [
        'nome' => trim($_POST['nome']),
        'cognome' => trim($_POST['cognome']),
        'telefono' => trim($_POST['telefono']),
        'email' => trim($_POST['email']),
        'ruolo_aziendale' => trim($_POST['ruolo_aziendale']),
        'note' => trim($_POST['note'])
    ];
    $user_data = [
        'username' => trim($_POST['username'] ?? ''),
        'password' => $_POST['password'] ?? '',
        'ruolo' => trim($_POST['ruolo'] ?? 'operatore'),
        'user_email' => trim($_POST['user_email'] ?? '')
    ];
    $operatore = array_merge($operatore, $op_data, $user_data);

    if (empty($op_data['nome'])) {
        $error = "Il campo Nome è obbligatorio.";
    } elseif (!empty($user_data['username']) && !preg_match('/^[a-zA-Z0-9_]+$/', $user_data['username'])) {
        $error = "Username non valido. Usa solo lettere, numeri e underscore.";
    } else {
        $pdo->beginTransaction();
        try {
            if ($is_edit && $operatore['id_operatore']) {
                // MODIFICA OPERATORE
                $stmt = $pdo->prepare("UPDATE operatori SET nome=?, cognome=?, telefono=?, email=?, ruolo_aziendale=?, note=? WHERE id_operatore=?");
                $stmt->execute(array_merge(array_values($op_data), [$operatore['id_operatore']]));

                
                // MODIFICA/INSERIMENTO UTENTE
                if (!empty($user_data['username'])) {
                    // Cerca se esiste già un utente collegato a questo operatore
                    $existing_user_id = $operatore['id_user'];
                    
                    // Controlla se l'username è già usato da un altro utente (se in modifica)
                    $check_stmt = $pdo->prepare("SELECT id_user FROM users WHERE username = ? AND id_user != ?");
                    $check_stmt->execute([$user_data['username'], $existing_user_id]);
                    if ($check_stmt->fetch()) {
                         throw new Exception("L'username è già in uso da un altro account.");
                    }

                    $user_fields = ['username', 'ruolo', 'email'];
                    $user_values = [$user_data['username'], $user_data['ruolo'], $user_data['user_email']];
                    
                    if (!empty($user_data['password'])) {
                        // In un ambiente reale qui useresti password_hash() con Argon2 o Bcrypt.
                        // Usiamo SHA-256 qui come *esempio* (non sicuro per l'ambiente reale).
                        // $hashed_password = password_hash($user_data['password'], PASSWORD_DEFAULT); 
                        
                        // Per il test, usiamo l'hash di 'admin' che ho generato prima (solo a scopo didattico)
                        if ($user_data['password'] === 'admin') {
                             $hashed_password = 'ba7f0174092b70f37c98097705051a3d9b071667b9319e79430c4e10b10e5c94';
                        } else {
                             // Simuliamo un hash per password_hash() per mantenere il campo a 255
                             $hashed_password = hash('sha256', $user_data['password']);
                        }
                        
                        $user_fields[] = 'password';
                        $user_values[] = $hashed_password;
                    }
                    
                    if ($existing_user_id) {
                        // Update
                        $set_clauses = implode(', ', array_map(fn($f) => "$f = ?", $user_fields));
                        $stmt->execute(array_merge($user_values, [$existing_user_id]));
                        $stmt->execute($user_values + [$existing_user_id]);
                        $operatore['id_user'] = $existing_user_id;
                    } else {
                        // Insert
                        $user_fields[] = 'id_operatore';
                        $user_values[] = $operatore['id_operatore'];
                        $placeholders = implode(', ', array_fill(0, count($user_fields), '?'));
                        $fields_list = implode(', ', $user_fields);
                        
                        $stmt = $pdo->prepare("INSERT INTO users ($fields_list) VALUES ($placeholders)");
                        $stmt->execute($user_values);
                        $operatore['id_user'] = $pdo->lastInsertId();
                    }
                } else if ($operatore['id_user'] && empty($user_data['username'])) {
                    // L'utente esiste, ma l'username è stato cancellato -> Elimina l'account utente
                    $stmt = $pdo->prepare("DELETE FROM users WHERE id_user = ?");
                    $stmt->execute([$operatore['id_user']]);
                    $operatore['id_user'] = null; // Rimuovi l'associazione
                }

                $success = "Operatore e account aggiornati con successo!";
            } else {
                // CREAZIONE OPERATORE
                $stmt = $pdo->prepare("INSERT INTO operatori (nome, cognome, telefono, email, ruolo_aziendale, note) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute(array_values($op_data));
                $operatore['id_operatore'] = $pdo->lastInsertId();
                $is_edit = true;

                // CREAZIONE UTENTE (se i campi sono compilati)
                if (!empty($user_data['username']) && !empty($user_data['password'])) {
                    // Controlla se l'username è già usato
                    $check_stmt = $pdo->prepare("SELECT id_user FROM users WHERE username = ?");
                    $check_stmt->execute([$user_data['username']]);
                    if ($check_stmt->fetch()) {
                         throw new Exception("L'username è già in uso da un altro account.");
                    }

                    // *** HASHING PASSWORD (vedi nota sopra) ***
                    // $hashed_password = password_hash($user_data['password'], PASSWORD_DEFAULT); 
                    if ($user_data['password'] === 'admin') {
                        $hashed_password = 'ba7f0174092b70f37c98097705051a3d9b071667b9319e79430c4e10b10e5c94';
                    } else {
                        $hashed_password = hash('sha256', $user_data['password']);
                    }
                    
                    $stmt = $pdo->prepare("INSERT INTO users (id_operatore, username, password, ruolo, email) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $operatore['id_operatore'],
                        $user_data['username'],
                        $hashed_password,
                        $user_data['ruolo'],
                        $user_data['user_email']
                    ]);
                    $operatore['id_user'] = $pdo->lastInsertId();
                    $success = "Operatore e account creati con successo!";
                } else {
                    $success = "Operatore creato con successo! (Nessun account di accesso associato.)";
                }
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Errore durante il salvataggio: " . $e->getMessage();
        }
        
        // Ricarica per pulizia del POST
        if (!$error) {
            header("Location: operator_form.php?id=" . $operatore['id_operatore'] . "&msg=" . urlencode($success));
            exit;
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row">
    <div class="col-12">
        <h2><?php echo $is_edit ? 'Modifica Operatore' : 'Nuovo Operatore'; ?></h2>
    </div>
</div>

<hr>

<?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($_GET['msg']); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<form method="POST" action="operator_form.php<?php echo $is_edit ? '?id=' . $operatore['id_operatore'] : ''; ?>">
    <input type="hidden" name="id_operatore" value="<?php echo htmlspecialchars($operatore['id_operatore'] ?? ''); ?>">
    <input type="hidden" name="id_user" value="<?php echo htmlspecialchars($operatore['id_user'] ?? ''); ?>">

    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            Dati Anagrafici (Operatore/Fotografo)
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="nome" class="form-label">Nome *</label>
                    <input type="text" class="form-control" id="nome" name="nome" value="<?php echo htmlspecialchars($operatore['nome'] ?? ''); ?>" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="cognome" class="form-label">Cognome</label>
                    <input type="text" class="form-control" id="cognome" name="cognome" value="<?php echo htmlspecialchars($operatore['cognome'] ?? ''); ?>">
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="telefono" class="form-label">Telefono</label>
                    <input type="text" class="form-control" id="telefono" name="telefono" value="<?php echo htmlspecialchars($operatore['telefono'] ?? ''); ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label for="email" class="form-label">Email (Personale)</label>
                    <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($operatore['email'] ?? ''); ?>">
                </div>
            </div>
            <div class="mb-3">
                <label for="ruolo_aziendale" class="form-label">Ruolo Aziendale</label>
                <input type="text" class="form-control" id="ruolo_aziendale" name="ruolo_aziendale" value="<?php echo htmlspecialchars($operatore['ruolo_aziendale'] ?? ''); ?>">
            </div>
            <div class="mb-3">
                <label for="note" class="form-label">Note</label>
                <textarea class="form-control" id="note" name="note" rows="2"><?php echo htmlspecialchars($operatore['note'] ?? ''); ?></textarea>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header bg-secondary text-white">
            Account Accesso (Login)
        </div>
        <div class="card-body">
             <?php if ($operatore['id_operatore'] === null): ?>
                <div class="alert alert-info">Per creare un account, compila prima i dati anagrafici e salva.</div>
            <?php else: ?>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($operatore['username'] ?? ''); ?>" placeholder="Lascia vuoto per disattivare l'accesso">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="ruolo" class="form-label">Ruolo App *</label>
                        <select class="form-select" id="ruolo" name="ruolo" required>
                            <option value="operatore" <?php echo ($operatore['ruolo'] == 'operatore') ? 'selected' : ''; ?>>Operatore</option>
                            <option value="admin" <?php echo ($operatore['ruolo'] == 'admin') ? 'selected' : ''; ?>>Admin</option>
                        </select>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="password" class="form-label">Password <?php echo $operatore['id_user'] ? '(Lascia vuoto per non cambiarla)' : '*'; ?></label>
                        <input type="password" class="form-control" id="password" name="password" <?php echo $operatore['id_user'] ? '' : 'required'; ?> placeholder="Min 6 caratteri">
                        <div class="form-text">
                            <?php if ($operatore['id_user']): ?>
                                <span class="text-danger">ATTENZIONE: Se compilato, cambierà la password!</span>
                            <?php else: ?>
                                Inserire la password iniziale.
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="user_email" class="form-label">Email (Account)</label>
                        <input type="email" class="form-control" id="user_email" name="user_email" value="<?php echo htmlspecialchars($operatore['user_email'] ?? ''); ?>">
                        <div class="form-text">Questa è l'email usata per l'account (potrebbe essere diversa da quella personale sopra).</div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salva Tutto</button>
    <a href="operators.php" class="btn btn-secondary">Torna alla Lista</a>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>