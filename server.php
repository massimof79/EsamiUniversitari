<?php
// server.php
// Riceve POST dal form, valida, scrive su file JSON e stampa l'elenco esami raggruppati per studente.

// CONFIGURAZIONE
$dataFile = __DIR__ . '/exams.json'; // file dove salviamo i record

// Funzione di utilità: pulisce output per prevenire XSS
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Legge i dati esistenti dal file JSON (se esiste)
function read_data($file) {
    if (!file_exists($file)) return [];
    $json = file_get_contents($file);
    $arr = json_decode($json, true);
    return is_array($arr) ? $arr : [];
}

// Salva un nuovo record con locking
function append_data($file, $record) {
    // leggi i vecchi dati, aggiungi, scrivi di nuovo con lock esclusivo
    $attempts = 0;
    do {
        $fp = fopen($file, 'c+'); // crea se non esiste
        if (!$fp) {
            return false;
        }
        if (flock($fp, LOCK_EX)) {
            // leggere contenuto
            $size = filesize($file);
            $contents = $size > 0 ? fread($fp, $size) : '';
            $data = $contents ? json_decode($contents, true) : [];
            if (!is_array($data)) $data = [];
            $data[] = $record;
            // riscrivi
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
            return true;
        } else {
            // couldn't lock; attendere e riprovare
            fclose($fp);
            usleep(50000); // 50ms
            $attempts++;
        }
    } while ($attempts < 5);
    return false;
}

// Validazione e acquisizione POST
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    // uso filter_input per base; poi validazioni aggiuntive
    $matricola = trim((string)filter_input(INPUT_POST, 'matricola', FILTER_SANITIZE_STRING));
    $cognome   = trim((string)filter_input(INPUT_POST, 'cognome', FILTER_SANITIZE_STRING));
    $nome      = trim((string)filter_input(INPUT_POST, 'nome', FILTER_SANITIZE_STRING));
    $esame     = trim((string)filter_input(INPUT_POST, 'esame', FILTER_SANITIZE_STRING));
    $votazione = filter_input(INPUT_POST, 'votazione', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 30]
    ]);
    $lode      = filter_input(INPUT_POST, 'lode') ? true : false;
    $corso     = trim((string)filter_input(INPUT_POST, 'corso', FILTER_SANITIZE_STRING));

    $errors = [];

    if ($matricola === '' || strlen($matricola) < 3) $errors[] = 'Matricola non valida.';
    if ($cognome === '') $errors[] = 'Cognome obbligatorio.';
    if ($nome === '') $errors[] = 'Nome obbligatorio.';
    if ($esame === '') $errors[] = 'Nome dell\'esame obbligatorio.';
    if ($votazione === false || $votazione === null) $errors[] = 'Votazione deve essere un intero tra 1 e 30.';
    if ($corso === '') $errors[] = 'Corso di laurea obbligatorio.';

    if (!empty($errors)) {
        // Stampo gli errori e link al form
        ?>
        <!doctype html>
        <html lang="it">
        <head><meta charset="utf-8"><title>Errore invio</title></head>
        <body>
          <h2>Errori nella validazione</h2>
          <ul>
            <?php foreach ($errors as $e): ?>
              <li><?php echo h($e); ?></li>
            <?php endforeach; ?>
          </ul>
          <p><a href="index.html">Torna al form</a></p>
        </body>
        </html>
        <?php
        exit;
    }

    // costruisco il record
    $record = [
        'matricola' => $matricola,
        'cognome'   => $cognome,
        'nome'      => $nome,
        'esame'     => $esame,
        'votazione' => (int)$votazione,
        'lode'      => $lode ? true : false,
        'corso'     => $corso,
        'ts'        => date('c')
    ];

    // salvo
    if (!append_data($dataFile, $record)) {
        http_response_code(500);
        echo "Errore di scrittura sul file dati. Verificare permessi.";
        exit;
    }

    // dopo il salvataggio, procederemo a leggere e stampare l'elenco completo (qui sotto)
}

// Leggo i dati e preparo la visualizzazione (sia dopo POST sia in caso si acceda direttamente a server.php)
$records = read_data($dataFile);

// Raggruppo per studente (matricola) e ordino per cognome/nome
$grouped = [];
foreach ($records as $r) {
    $key = $r['matricola'] . '||' . ($r['cognome'] ?? '') . '||' . ($r['nome'] ?? '');
    if (!isset($grouped[$key])) $grouped[$key] = [];
    $grouped[$key][] = $r;
}
// Ordino gli studenti per cognome/nome
uksort($grouped, function($a, $b) {
    $pa = explode('||', $a);
    $pb = explode('||', $b);
    $ca = $pa[1] . ' ' . $pa[2];
    $cb = $pb[1] . ' ' . $pb[2];
    return strcasecmp($ca, $cb);
});

?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title>Elenco Esami registrati</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body { font-family: Arial, Helvetica, sans-serif; margin: 20px; }
    .student { margin-bottom: 28px; padding: 12px; border: 1px solid #ddd; border-radius:8px; }
    table { width:100%; border-collapse: collapse; }
    th, td { padding:8px; border: 1px solid #eaeaea; text-align:left; }
    .green { background: #dff0d8; } /* voti > 28 */
    .orange { background: #fff3cd; } /* voti < 28 */
    .neutral { background: #f8f9fa; } /* per 28 */
    .meta { font-size:0.95em; color:#333; margin-bottom:8px; }
    .avg { font-weight:600; }
    .back { margin-top:12px; display:inline-block; }
  </style>
</head>
<body>
  <h1>Elenco esami registrati</h1>
  <p>Totale record: <?php echo count($records); ?>. <a href="index.html" class="back">Inserisci un nuovo esito</a></p>

  <?php if (empty($grouped)): ?>
    <p>Non ci sono ancora esami registrati.</p>
  <?php else: ?>

    <?php foreach ($grouped as $key => $items): 
        // estraggo matricola/cognome/nome dal key
        list($mat, $cog, $nom) = explode('||', $key);
        // calcolo media
        $sum = 0; $n = 0;
        foreach ($items as $it) { $sum += (int)$it['votazione']; $n++; }
        $avg = $n ? ($sum / $n) : 0;
        ?>
        <div class="student">
          <div class="meta">
            <strong><?php echo h($cog . ' ' . $nom); ?></strong>
            &nbsp;|&nbsp; Matricola: <?php echo h($mat); ?>
            &nbsp;|&nbsp; Esami registrati: <?php echo $n; ?>
            &nbsp;|&nbsp; Media voto: <span class="avg"><?php echo number_format($avg, 2); ?></span>
          </div>

          <table aria-describedby="student-<?php echo h($mat); ?>">
            <thead>
              <tr>
                <th>Data inserimento</th>
                <th>Esame</th>
                <th>Votazione</th>
                <th>Lode</th>
                <th>Corso di Laurea</th>
              </tr>
            </thead>
            <tbody>
            <?php
            // ordino gli esami per data (più recenti prima)
            usort($items, function($a, $b) {
                return strcmp($b['ts'] ?? '', $a['ts'] ?? '');
            });
            foreach ($items as $it):
                $v = (int)$it['votazione'];
                $class = 'neutral';
                if ($v > 28) $class = 'green';
                else if ($v < 28) $class = 'orange';
            ?>
              <tr class="<?php echo $class; ?>">
                <td><?php echo h(isset($it['ts']) ? date('Y-m-d H:i', strtotime($it['ts'])) : '-'); ?></td>
                <td><?php echo h($it['esame']); ?></td>
                <td><?php echo h($v); ?></td>
                <td><?php echo $it['lode'] ? 'Sì' : 'No'; ?></td>
                <td><?php echo h($it['corso']); ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
    <?php endforeach; ?>

  <?php endif; ?>

  <p><a href="index.html" class="back">Torna al form</a></p>
</body>
</html>
