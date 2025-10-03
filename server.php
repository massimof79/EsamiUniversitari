<?php
// server.php (versione senza salvataggio su file)
// Riceve POST dal form, valida e stampa l'elenco (solo per la richiesta corrente).

// Funzione di utilità: pulisce output per prevenire XSS
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Recupero e validazione dei dati solo dalla richiesta corrente (nessun persist)
$records = [];

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    // Sanitizzazione di base
    $matricola = trim((string)(filter_input(INPUT_POST, 'matricola', FILTER_SANITIZE_STRING) ?? ''));
    $cognome   = trim((string)(filter_input(INPUT_POST, 'cognome', FILTER_SANITIZE_STRING) ?? ''));
    $nome      = trim((string)(filter_input(INPUT_POST, 'nome', FILTER_SANITIZE_STRING) ?? ''));
    $esame     = trim((string)(filter_input(INPUT_POST, 'esame', FILTER_SANITIZE_STRING) ?? ''));
    $votazione = filter_input(INPUT_POST, 'votazione', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 30]
    ]);
    $lode      = filter_input(INPUT_POST, 'lode') ? true : false;
    $corso     = trim((string)(filter_input(INPUT_POST, 'corso', FILTER_SANITIZE_STRING) ?? ''));

    $errors = [];

    if ($matricola === '' || strlen($matricola) < 3) $errors[] = 'Matricola non valida.';
    if ($cognome === '') $errors[] = 'Cognome obbligatorio.';
    if ($nome === '') $errors[] = 'Nome obbligatorio.';
    if ($esame === '') $errors[] = 'Nome dell\'esame obbligatorio.';
    if ($votazione === false || $votazione === null) $errors[] = 'Votazione deve essere un intero tra 1 e 30.';
    if ($corso === '') $errors[] = 'Corso di laurea obbligatorio.';

    if (empty($errors)) {
        // costruisco il record (solo in memoria per la visualizzazione corrente)
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
        $records[] = $record;
    }
}
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title>Elenco Esami (visualizzazione temporanea)</title>
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
    .errors { color: #b00020; }
  </style>
</head>
<body>
  <h1>Elenco Esami (visualizzazione temporanea)</h1>
  <p><a href="index.html" class="back">Torna al form</a></p>

<?php if (!empty($errors)): ?>
  <div class="errors">
    <h2>Errori di validazione</h2>
    <ul>
      <?php foreach ($errors as $e): ?>
        <li><?php echo h($e); ?></li>
      <?php endforeach; ?>
    </ul>
    <p><a href="index.html">Correggi i dati</a></p>
  </div>
<?php endif; ?>

<?php if (empty($records)): ?>
  <p>Non ci sono record da visualizzare. Invia il form da <a href="index.html">index.html</a> per vedere i risultati.</p>
<?php else: ?>

  <?php
    // Raggruppo per studente (matricola); qui ci saranno al massimo i record della richiesta corrente
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

    foreach ($grouped as $key => $items):
        list($mat, $cog, $nom) = explode('||', $key);
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

      <table>
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
