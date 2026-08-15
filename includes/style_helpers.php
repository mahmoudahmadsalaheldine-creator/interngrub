<?php
// TeamInternGrub — Design-system style helpers (frontend-only).
// Color maps + small render helpers used across all pages instead of ad-hoc badge classes.

// Note: text color for green-background pills (Present/Active/Approved/Done) is
// intentionally green (#15803D), not the purple (#6A4D94) seen in one spot of the
// source reference file — confirmed by the user as the correct, consistent choice.
$attendanceStat = [
    'present'  => ['bg' => '#E3F5EA', 'fg' => '#15803D', 'label' => 'Present'],
    'late'     => ['bg' => '#FDECC8', 'fg' => '#B45309', 'label' => 'Late'],
    'absent'   => ['bg' => '#FCE4E4', 'fg' => '#DC2626', 'label' => 'Absent'],
    'on_leave' => ['bg' => '#E0EAFF', 'fg' => '#2563EB', 'label' => 'On leave'],
    'weekend'  => ['bg' => '#EEF1F4', 'fg' => '#64748B', 'label' => 'Weekend'],
];

$internStat = [
    'active'     => ['bg' => '#E3F5EA', 'fg' => '#15803D', 'label' => 'Active'],
    'completed'  => ['bg' => '#E0EAFF', 'fg' => '#2563EB', 'label' => 'Completed'],
    'terminated' => ['bg' => '#FCE4E4', 'fg' => '#DC2626', 'label' => 'Terminated'],
    'on_leave'   => ['bg' => '#FDECC8', 'fg' => '#B45309', 'label' => 'On leave'],
];

$leaveStat = [
    'pending'  => ['bg' => '#FDECC8', 'fg' => '#B45309', 'label' => 'Pending'],
    'approved' => ['bg' => '#E3F5EA', 'fg' => '#15803D', 'label' => 'Approved'],
    'rejected' => ['bg' => '#FCE4E4', 'fg' => '#DC2626', 'label' => 'Rejected'],
];

$taskStat = [
    'to_do'          => ['bg' => '#EEF1F4', 'fg' => '#64748B', 'label' => 'To do'],
    'in_progress'    => ['bg' => '#FDECC8', 'fg' => '#B45309', 'label' => 'In progress'],
    'pending_review' => ['bg' => '#E0EAFF', 'fg' => '#2563EB', 'label' => 'Pending review'],
    'done'           => ['bg' => '#E3F5EA', 'fg' => '#15803D', 'label' => 'Done'],
];

$hrAppStat = [
    'new'             => ['bg' => '#EFF6FF', 'fg' => '#1D4ED8', 'label' => 'New'],
    'first_interview' => ['bg' => '#EDE8F5', 'fg' => '#7B5EA7', 'label' => 'First Interview'],
    'accepted'        => ['bg' => '#E3F5EA', 'fg' => '#15803D', 'label' => 'Accepted'],
    'rejected'        => ['bg' => '#FCE4E4', 'fg' => '#DC2626', 'label' => 'Rejected'],
];

$hrJobStat = [
    'open'   => ['bg' => '#E3F5EA', 'fg' => '#15803D', 'label' => 'Open'],
    'closed' => ['bg' => '#EEF1F4', 'fg' => '#64748B', 'label' => 'Closed'],
];

$hrIntResult = [
    'pending' => ['bg' => '#FFF8E6', 'fg' => '#B45309', 'label' => 'Pending'],
    'passed'  => ['bg' => '#E3F5EA', 'fg' => '#15803D', 'label' => 'Passed'],
    'failed'  => ['bg' => '#FCE4E4', 'fg' => '#DC2626', 'label' => 'Failed'],
];

$prioMap = [
    'high'   => ['bg' => '#FCE4E4', 'fg' => '#DC2626', 'label' => 'High'],
    'medium' => ['bg' => '#FDECC8', 'fg' => '#B45309', 'label' => 'Medium'],
    'low'    => ['bg' => '#E8EDF2', 'fg' => '#64748B', 'label' => 'Low'],
];

/**
 * The single authoritative status/priority pill renderer.
 */
function statusPill($bg, $fg, $label)
{
    return '<span style="display:inline-flex;align-items:center;gap:6px;padding:4px 11px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap;text-transform:capitalize;background:' . $bg . ';color:' . $fg . ';">' . htmlspecialchars($label) . '</span>';
}

/**
 * Render a pill by looking up $key in $map (one of the maps above).
 * Falls back to a neutral gray pill if the key isn't found.
 */
function statusPillFor($map, $key)
{
    $entry = $map[$key] ?? ['bg' => '#EEF1F4', 'fg' => '#64748B', 'label' => (string)$key];
    return statusPill($entry['bg'], $entry['fg'], $entry['label']);
}

/**
 * Inline style string for a circular (or square, for notif icon boxes) avatar.
 */
function avatarStyle($color, $size, $fontSize)
{
    return "width:{$size}px;height:{$size}px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:{$fontSize}px;background:{$color};flex-shrink:0;letter-spacing:.01em;";
}

/**
 * Deterministic per-intern avatar color from a small palette.
 */
function internColor($intern_id)
{
    $palette = ['#7B5EA7', '#0EA5E9', '#DB2777'];
    return $palette[$intern_id % count($palette)];
}

/**
 * Progress-bar fill color threshold helper (used for attendance-rate bars).
 */
function progFill($percent)
{
    return $percent >= 45 ? '#16A34A' : '#F59E0B';
}

/**
 * Renders a styled time chip for HH:MM:SS values in tables.
 * Pass null or '' to get an em dash.
 */
function timePill($time, $showIcon = true)
{
    if ($time === null || $time === '') {
        return '<span style="color:var(--muted-lightest);"></span>';
    }
    $clock = $showIcon ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/></svg>' : '';
    return '<span class="time-chip">' . $clock . htmlspecialchars($time) . '</span>';
}

