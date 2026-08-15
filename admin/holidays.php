<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');

$pdo  = getDB();
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
if ($year < 2000 || $year > 2100) $year = (int)date('Y');

// ── Lebanon national holidays (fixed dates) ──────────────────────────────────
function getLebanonFixedHolidays(int $year): array {
    $fixed = [
        ['01-01', "New Year's Day"],
        ['02-09', "Saint Maron's Day"],
        ['03-25', 'Annunciation Day'],
        ['05-01', 'Labor Day'],
        ['05-06', "Martyrs' Day"],
        ['08-15', 'Assumption Day'],
        ['11-01', "All Saints' Day"],
        ['11-22', 'Independence Day'],
        ['12-25', 'Christmas Day'],
    ];
    // Approximate Islamic holidays by year (lunar-based, may shift 1 day)
    $islamic = [
        2024 => [['04-10','Eid Al-Fitr'],['06-17','Eid Al-Adha'],['07-07','Islamic New Year'],['09-15','Moulid Al Nabi']],
        2025 => [['03-30','Eid Al-Fitr'],['06-06','Eid Al-Adha'],['06-26','Islamic New Year'],['09-04','Moulid Al Nabi']],
        2026 => [['03-20','Eid Al-Fitr'],['05-27','Eid Al-Adha'],['06-16','Islamic New Year'],['08-25','Moulid Al Nabi']],
        2027 => [['03-09','Eid Al-Fitr'],['05-16','Eid Al-Adha'],['06-06','Islamic New Year'],['08-14','Moulid Al Nabi']],
    ];
    $result = [];
    foreach ($fixed as [$md, $name]) {
        $result[] = ['date' => "$year-$md", 'name' => $name];
    }
    foreach (($islamic[$year] ?? []) as [$md, $name]) {
        $result[] = ['date' => "$year-$md", 'name' => $name];
    }
    return $result;
}

function importLebanonHolidays(PDO $pdo, int $year): int {
    $list = getLebanonFixedHolidays($year);

    $existStmt = $pdo->prepare("SELECT holiday_date FROM holiday WHERE YEAR(holiday_date) = ?");
    $existStmt->execute([$year]);
    $existing = array_flip($existStmt->fetchAll(PDO::FETCH_COLUMN));

    $ins   = $pdo->prepare("INSERT INTO holiday (title, holiday_date, type) VALUES (?, ?, 'national')");
    $count = 0;
    foreach ($list as $h) {
        if (!isset($existing[$h['date']])) {
            $ins->execute([$h['name'], $h['date']]);
            $count++;
        }
    }
    return $count;
}

// ── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (isset($_POST['add_holiday'])) {
        $title = trim($_POST['title'] ?? '');
        $date  = $_POST['date'] ?? '';
        $hErr = vFirst([
            vRequired($title, 'Title'), vMaxLen($title, 100, 'Title'),
            vRequired($date, 'Date'), vDate($date, 'Date'),
        ]);
        if ($hErr !== '') { setToast($hErr, 'error'); }
        elseif ($title !== '' && $date !== '') {
            $pdo->prepare("INSERT INTO holiday (title, holiday_date, type) VALUES (?, ?, 'custom')")->execute([$title, $date]);
            setToast('Holiday added.');
        }
        header('Location: ' . BASE_URL . '/admin/holidays.php?year=' . $year); exit;
    }
    if (isset($_POST['delete_holiday'])) {
        $hid = (int)($_POST['holiday_id'] ?? 0);
        if ($hid > 0) {
            $pdo->prepare("DELETE FROM holiday WHERE holiday_id = ?")->execute([$hid]);
            setToast('Holiday removed.');
        }
        header('Location: ' . BASE_URL . '/admin/holidays.php?year=' . $year); exit;
    }
    if (isset($_POST['import_holidays'])) {
        $n = importLebanonHolidays($pdo, $year);
        if ($n === -1)     setToast('Could not reach the holidays API. Check your internet connection.', 'error');
        elseif ($n === 0)  setToast('All holidays already up to date.');
        else               setToast("$n holidays imported for $year.");
        header('Location: ' . BASE_URL . '/admin/holidays.php?year=' . $year); exit;
    }
}

// ── Auto-import if no holidays this year ─────────────────────────────────────
$yearCountStmt = $pdo->prepare("SELECT COUNT(*) FROM holiday WHERE YEAR(holiday_date) = ?");
$yearCountStmt->execute([$year]);
if ((int)$yearCountStmt->fetchColumn() === 0) {
    $autoImported = importLebanonHolidays($pdo, $year);
    if ($autoImported > 0) {
        setToast("$autoImported Lebanon holidays imported for $year.");
    }
}

// ── Fetch all holidays for this year ─────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM holiday WHERE YEAR(holiday_date) = ? ORDER BY holiday_date ASC");
$stmt->execute([$year]);
$holidayMap = [];
foreach ($stmt->fetchAll() as $h) {
    $holidayMap[$h['holiday_date']] = $h;
}

$today    = date('Y-m-d');
$pageTitle    = 'Holidays';
$pageSubtitle = 'Lebanon public holidays and company days off';
require_once __DIR__ . '/../includes/layout_top.php';
?>

<style>
.holidays-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 10px;
}
.year-nav { display: flex; align-items: center; gap: 10px; }
.year-nav-btn {
    width: 30px; height: 30px;
    border-radius: 7px;
    border: 1px solid var(--border);
    background: #fff;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    color: var(--muted);
    text-decoration: none;
    transition: border-color .15s, color .15s;
}
.year-nav-btn:hover { border-color: var(--navy); color: var(--navy); }
.year-label {
    font-size: 18px;
    font-weight: 700;
    color: var(--navy);
    min-width: 56px;
    text-align: center;
}
.header-actions { display: flex; gap: 8px; align-items: center; }

.year-calendar {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
}
@media (max-width: 1100px) { .year-calendar { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 780px)  { .year-calendar { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 460px)  { .year-calendar { grid-template-columns: 1fr; } }

.month-card {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 10px;
}
.month-header {
    padding: 9px 12px 8px;
    background: #475569;
    border-radius: 9px 9px 0 0;
}
.month-name {
    font-size: 11px;
    font-weight: 700;
    color: #fff;
    text-transform: uppercase;
    letter-spacing: .08em;
    text-align: center;
}
.month-body { padding: 8px 8px 10px; }
.day-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 1px;
}
.dh {
    font-size: 9px;
    font-weight: 600;
    color: var(--muted-light);
    text-align: center;
    padding: 2px 0 5px;
    letter-spacing: .03em;
}
.dc {
    aspect-ratio: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    color: var(--muted-dark);
    border-radius: 50%;
    cursor: default;
    font-variant-numeric: tabular-nums;
    transition: background .1s;
}
.dc.empty { visibility: hidden; }
.dc.weekend { color: var(--muted-light); }
.dc.past { opacity: .4; }
.dc.today {
    background: var(--navy);
    color: #fff;
    font-weight: 700;
}
.dc.holiday {
    background: #E3F5EA;
    color: #15803D;
    font-weight: 700;
    cursor: pointer;
}
.dc.holiday:hover { background: #CCEEDD; }
.dc.holiday.custom {
    background: #FEF3C7;
    color: #92400E;
}
.dc.holiday.custom:hover { background: #FDE68A; }
.dc.holiday.today {
    background: var(--navy);
    color: #fff;
    box-shadow: 0 0 0 2px #fff, 0 0 0 3.5px #15803D;
}
.dc.holiday.custom.today {
    box-shadow: 0 0 0 2px #fff, 0 0 0 3.5px #92400E;
}
.dc.holiday.past { opacity: .5; }

.cal-legend {
    display: flex;
    gap: 18px;
    align-items: center;
    margin-bottom: 14px;
    font-size: 11.5px;
    color: var(--muted);
}
.legend-dot {
    width: 9px; height: 9px;
    border-radius: 50%;
    display: inline-block;
    margin-right: 5px;
    vertical-align: middle;
}

.hol-popover {
    position: fixed;
    z-index: 99999;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 10px;
    box-shadow: 0 8px 28px rgba(45,43,107,.13);
    padding: 14px 16px;
    width: 224px;
    display: none;
    pointer-events: all;
}
.hol-popover.visible { display: block; }
.hol-pop-icon {
    width: 30px; height: 30px;
    border-radius: 7px;
    background: #E3F5EA;
    display: flex; align-items: center; justify-content: center;
    margin-bottom: 9px;
}
.hol-pop-title {
    font-size: 13px;
    font-weight: 700;
    color: var(--navy);
    margin-bottom: 2px;
    line-height: 1.4;
}
.hol-pop-date {
    font-size: 11.5px;
    color: var(--muted-light);
    margin-bottom: 0;
}
.hol-pop-divider { border: none; border-top: 1px solid var(--border); margin: 10px 0; }
.hol-pop-del {
    width: 100%;
    padding: 7px 0;
    border-radius: 7px;
    border: 1px solid #FECACA;
    background: #FEF2F2;
    color: #DC2626;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 5px;
    transition: background .12s;
}
.hol-pop-del:hover { background: #FEE2E2; }
.hol-pop-lock {
    font-size: 11.5px;
    color: var(--muted-light);
    display: flex; align-items: center; justify-content: center; gap: 5px;
    padding: 4px 0;
}
.hol-pop-confirm-text {
    font-size: 12.5px;
    color: var(--navy);
    font-weight: 600;
    margin-bottom: 10px;
}
.hol-pop-confirm-row { display: flex; gap: 6px; }
.hol-pop-yes {
    flex: 1; padding: 7px 0;
    border-radius: 7px;
    border: none;
    background: #DC2626;
    color: #fff;
    font-size: 12px; font-weight: 600; cursor: pointer;
}
.hol-pop-no {
    flex: 1; padding: 7px 0;
    border-radius: 7px;
    border: 1px solid var(--border);
    background: #fff;
    color: var(--muted-dark);
    font-size: 12px; font-weight: 600; cursor: pointer;
}
.hol-pop-yes:hover { background: #B91C1C; }
.hol-pop-no:hover  { background: #F8FAFC; }
</style>

<!-- Header -->
<div class="holidays-header">
    <div class="year-nav">
        <a href="?year=<?= $year - 1 ?>" class="year-nav-btn" title="Previous year">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
        </a>
        <span class="year-label"><?= $year ?></span>
        <a href="?year=<?= $year + 1 ?>" class="year-nav-btn" title="Next year">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
    </div>
    <div class="header-actions">
        <form method="POST" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="import_holidays" value="1">
            <button type="submit" class="btn btn-outline" style="font-size:13px;">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.51"/></svg>
                Import LB Holidays <?= $year ?>
            </button>
        </form>
        <button type="button" class="btn btn-primary" onclick="openModal('addHolidayModal')">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add Holiday
        </button>
    </div>
</div>

<!-- Legend -->
<div class="cal-legend">
    <span><span class="legend-dot" style="background:#E3F5EA;border:1.5px solid #15803D;"></span> Official Holiday</span>
    <span><span class="legend-dot" style="background:#FEF3C7;border:1.5px solid #92400E;"></span> Custom Holiday</span>
    <span><span class="legend-dot" style="background:var(--navy);"></span> Today</span>
    <span style="margin-left:4px;"><?= count($holidayMap) ?> holiday<?= count($holidayMap) !== 1 ? 's' : '' ?> in <?= $year ?></span>
</div>

<!-- Year Calendar -->
<div class="year-calendar" id="yearCal">
<?php
$dayHeaders  = ['Su','Mo','Tu','We','Th','Fr','Sa'];
$monthColors = array_fill(0, 12, '#7C3AED');
for ($m = 1; $m <= 12; $m++):
    $monthName   = date('F', mktime(0,0,0,$m,1,$year));
    $daysInMonth = (int)date('t', mktime(0,0,0,$m,1,$year));
    $firstDow    = (int)date('w', mktime(0,0,0,$m,1,$year)); // 0=Sun
    $mColor      = $monthColors[$m - 1];
?>
<div class="month-card">
    <div class="month-header" style="background:<?= $mColor ?>;">
        <div class="month-name"><?= $monthName ?></div>
    </div>
    <div class="month-body">
    <div class="day-grid">
        <?php foreach ($dayHeaders as $dh): ?>
            <div class="dh"><?= $dh ?></div>
        <?php endforeach; ?>
        <?php for ($i = 0; $i < $firstDow; $i++): ?>
            <div class="dc empty"></div>
        <?php endfor; ?>
        <?php for ($d = 1; $d <= $daysInMonth; $d++):
            $dateStr  = sprintf('%04d-%02d-%02d', $year, $m, $d);
            $dow      = (int)date('w', mktime(0,0,0,$m,$d,$year));
            $isToday  = ($dateStr === $today);
            $isPast   = ($dateStr < $today);
            $holiday  = $holidayMap[$dateStr] ?? null;
            $isWeekend = ($dow === 0 || $dow === 6);
            $cls = 'dc';
            if ($holiday)   $cls .= ' holiday';
            if ($holiday && ($holiday['type'] ?? 'national') === 'custom') $cls .= ' custom';
            if ($isToday)   $cls .= ' today';
            if ($isPast && !$isToday) $cls .= ' past';
            if ($isWeekend && !$holiday && !$isToday) $cls .= ' weekend';
        ?>
        <div class="<?= $cls ?>"
            <?php if ($holiday): ?>
                data-hid="<?= (int)$holiday['holiday_id'] ?>"
                data-title="<?= htmlspecialchars($holiday['title'], ENT_QUOTES) ?>"
                data-date="<?= htmlspecialchars(date('M j, Y', strtotime($dateStr)), ENT_QUOTES) ?>"
                data-type="<?= htmlspecialchars($holiday['type'] ?? 'national', ENT_QUOTES) ?>"
            <?php endif; ?>
        ><?= $d ?></div>
        <?php endfor; ?>
    </div>
    </div><!-- month-body -->
</div>
<?php endfor; ?>
</div>

<!-- Popover -->
<div class="hol-popover" id="holPopover">
    <!-- Default view -->
    <div id="holViewDefault">
        <div class="hol-pop-icon">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#15803D" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        </div>
        <div class="hol-pop-title" id="holPopTitle"></div>
        <div class="hol-pop-date" id="holPopDate"></div>
        <hr class="hol-pop-divider">
        <button class="hol-pop-del" id="holDelBtn" onclick="holAskConfirm()">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
            Remove Holiday
        </button>
        <div id="holLockMsg" style="display:none;font-size:11.5px;color:#94A3B8;text-align:center;padding:6px 0;display:flex;align-items:center;justify-content:center;gap:5px;">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#94A3B8" stroke-width="2.2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            Official holiday, cannot be removed
        </div>
    </div>
    <!-- Confirm view -->
    <div id="holViewConfirm" style="display:none;">
        <div class="hol-pop-confirm-text">Remove this holiday?</div>
        <div class="hol-pop-confirm-row">
            <form method="POST" style="flex:1;display:flex;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="holiday_id" id="dh_id">
                <input type="hidden" name="delete_holiday" value="1">
                <button type="submit" class="hol-pop-yes" style="width:100%;">Yes, Remove</button>
            </form>
            <button type="button" class="hol-pop-no" onclick="holCancelConfirm()">Cancel</button>
        </div>
    </div>
</div>

<!-- Add Holiday Modal -->
<div class="modal-overlay" id="addHolidayModal" style="display:none;">
    <div class="modal" style="width:420px;">
        <div class="modal-header">
            <div><div class="modal-title">Add Holiday</div><div class="modal-subtitle">Interns will be notified on login</div></div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Holiday name <span style="color:#DC2626;">*</span></label>
                    <input type="text" name="title" class="form-control" placeholder="e.g. Eid Al-Fitr" required data-val-required="Title" data-val-maxlen="100" data-val-label="Title">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Date <span style="color:#DC2626;">*</span></label>
                    <input type="date" name="date" class="form-control" required data-val-required="Date" data-val-date data-val-label="Date">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('addHolidayModal')">Cancel</button>
                <button type="submit" name="add_holiday" value="1" class="btn btn-primary">Add Holiday</button>
            </div>
        </form>
    </div>
</div>


<script>
// Move popover to body so no parent element can clip or block it
var popover = document.getElementById('holPopover');
document.body.appendChild(popover);

var activeHid = null;

// Event delegation — one listener on the whole calendar
document.getElementById('yearCal').addEventListener('click', function(e) {
    var cell = e.target.closest('[data-hid]');
    if (!cell) return;
    e.stopPropagation();

    activeHid = cell.dataset.hid;
    document.getElementById('holPopTitle').textContent = cell.dataset.title;
    document.getElementById('holPopDate').textContent  = cell.dataset.date;
    holResetView();

    var isNational = (cell.dataset.type !== 'custom');
    document.getElementById('holDelBtn').style.display  = isNational ? 'none'  : 'flex';
    document.getElementById('holLockMsg').style.display = isNational ? 'flex'  : 'none';

    var icon = document.querySelector('.hol-pop-icon');
    var iconSvg = icon.querySelector('svg');
    if (isNational) {
        icon.style.background = '#E3F5EA';
        iconSvg.setAttribute('stroke', '#15803D');
    } else {
        icon.style.background = '#FEF3C7';
        iconSvg.setAttribute('stroke', '#92400E');
    }

    popover.classList.add('visible');

    var rect = cell.getBoundingClientRect();
    var pw   = 230;
    var left = rect.left + rect.width / 2 - pw / 2;
    var top  = rect.bottom + 8;
    if (left + pw > window.innerWidth  - 12) left = window.innerWidth  - pw - 12;
    if (left < 12) left = 12;
    if (top  + 150 > window.innerHeight - 12) top  = rect.top - 150 - 8;
    popover.style.left = left + 'px';
    popover.style.top  = top  + 'px';
});

function holAskConfirm() {
    document.getElementById('dh_id').value = activeHid;
    document.getElementById('holViewDefault').style.display = 'none';
    document.getElementById('holViewConfirm').style.display = 'block';
}

function holCancelConfirm() { holResetView(); }

function holResetView() {
    document.getElementById('holViewDefault').style.display = 'block';
    document.getElementById('holViewConfirm').style.display = 'none';
    // del/lock visibility is set per-click based on type, not reset here
}

document.addEventListener('click', function(e) {
    if (popover.classList.contains('visible') && !popover.contains(e.target)) {
        popover.classList.remove('visible');
        holResetView();
    }
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { popover.classList.remove('visible'); holResetView(); }
});
</script>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>
