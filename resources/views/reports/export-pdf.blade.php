<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Panabo SDA Learning Center — Report</title>
<style>
  * { font-family: DejaVu Sans, sans-serif; }
  body { margin: 0; padding: 24px; color: #111827; font-size: 11px; }

  /* ── Official school letterhead ── */
  .letterhead-table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
  .letterhead-table td { border: none; padding: 0; vertical-align: middle; }
  .lh-logo { width: 66px; text-align: center; }
  .lh-logo img { width: 50px; height: auto; border-radius: 50%; }
  .lh-text { text-align: center; }
  .lh-name { font-size: 16px; font-weight: 800; color: #14532d; margin: 0; letter-spacing: 0.02em; }
  .lh-motto { font-size: 9.5px; font-style: italic; color: #374151; margin: 2px 0; }
  .lh-reg { font-size: 8.5px; color: #6b7280; margin: 0; text-transform: uppercase; letter-spacing: 0.03em; }
  .lh-city { font-size: 8.5px; color: #6b7280; margin: 0; text-transform: uppercase; letter-spacing: 0.08em; }
  .letterhead-rule { border: none; border-top: 3px double #14532d; margin: 6px 0 12px; }

  .header { text-align: center; padding-bottom: 10px; margin-bottom: 16px; }
  .header .system-tag { font-size: 8.5px; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.1em; margin: 0 0 4px; }
  .header h2 { font-size: 14px; margin: 4px 0 2px; color: #111827; }
  .header p  { font-size: 10px; color: #6b7280; margin: 0; }

  .meta { margin-bottom: 14px; font-size: 11px; }
  .meta p { margin: 2px 0; }
  .meta strong { color: #374151; }

  table.data { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
  th, td { border: 1px solid #d1d5db; padding: 6px 8px; text-align: left; vertical-align: top; }
  th { background: #ede9fe; color: #6d28d9; font-size: 10px; text-transform: uppercase; }
  td { font-size: 10px; }

  h3 { font-size: 13px; color: #374151; margin: 14px 0 6px; border-left: 4px solid #7c3aed; padding-left: 8px; }

  .footer { margin-top: 18px; font-size: 9px; color: #9ca3af; text-align: center; border-top: 1px solid #e5e7eb; padding-top: 6px; }
  .empty { color: #9ca3af; font-style: italic; }

  .signature { margin-top: 32px; }
  .signature table { border: none; width: 100%; }
  .signature td { border: none; text-align: center; padding-top: 26px; font-size: 10px; }
  .signature .line { display: inline-block; width: 200px; border-top: 1px solid #374151; padding-top: 4px; }
  .signature .role { font-size: 8.5px; color: #6b7280; margin: 3px 0 0; }
</style>
</head>
<body>

<table class="letterhead-table">
  <tr>
    <td class="lh-logo"><img src="data:image/jpeg;base64,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"></td>
    <td class="lh-text">
      <p class="lh-name">PANABO SDA LEARNING CENTER, INC.</p>
      <p class="lh-motto">&ldquo;Developing Leaders Today That Will Walk Jesus into Eternity&rdquo;</p>
      <p class="lh-reg">Government Recognition No. 002 S. 2013 &nbsp;&bull;&nbsp; School ID No. 405590</p>
      <p class="lh-city">Panabo City</p>
    </td>
    <td class="lh-logo"><img src="data:image/jpeg;base64,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"></td>
  </tr>
</table>
<hr class="letterhead-rule">

<div class="header">
  <p class="system-tag">Generated via StudyNest Learning Management System</p>
  <h2>
    @switch($report_type)
      @case('student_lesson_activity') Student Lesson &amp; Activity Report @break
      @case('weekly_activity')          Weekly Teacher Activity Report @break
      @case('subject_report')           Subject Report @break
      @case('student_report')           Student Report @break
      @case('task_report')              Task Scores Report @break
      @default                          Report @break
    @endswitch
  </h2>
  <p>Generated on {{ $generated_at }}</p>
</div>

<div class="meta">
  @if(!empty($data['teacher']['name']))
    <p><strong>Teacher:</strong> {{ $data['teacher']['name'] }}</p>
  @endif
  @if(!empty($data['subject']['name']))
    <p><strong>Subject:</strong> {{ $data['subject']['name'] }} &mdash; {{ $data['subject']['grade_level'] ?? '' }}</p>
  @endif
  @if(!empty($data['student']['name']))
    <p><strong>Student:</strong> {{ $data['student']['name'] }}</p>
  @endif
  @if(!empty($data['activity']['title']))
    <p><strong>Task:</strong> {{ $data['activity']['title'] }} &mdash; {{ $data['activity']['type'] ?? '' }}</p>
  @endif
  @if(!empty($data['activity_type']))
    <p><strong>Task type:</strong> {{ $data['activity_type'] }}</p>
  @endif
  @if(!empty($data['date_from']) || !empty($data['date_to']))
    <p><strong>Date range:</strong> {{ $data['date_from'] ?? '—' }} &nbsp;to&nbsp; {{ $data['date_to'] ?? '—' }}</p>
  @endif
  @if(!empty($data['week_start']))
    <p><strong>Week:</strong> {{ $data['week_start'] }} &nbsp;to&nbsp; {{ $data['week_end'] }}</p>
  @endif
</div>

{{-- ── Student Lesson & Activity Report ── --}}
@if($report_type === 'student_lesson_activity')
  <table class="data">
    <thead>
      <tr>
        <th>Student</th><th>Subject</th>
        <th>Viewed</th><th>Downloaded</th>
        <th>Assignment</th><th>Task</th><th>Quiz</th>
        <th>Score</th><th>Date</th><th>Remarks</th>
      </tr>
    </thead>
    <tbody>
      @forelse(($data['rows'] ?? []) as $r)
        <tr>
          <td>{{ $r['student_name'] }}</td>
          <td>{{ $data['subject']['name'] ?? '' }}</td>
          <td>{{ $r['lessons_viewed'] }}</td>
          <td>{{ $r['lessons_downloaded'] }}</td>
          <td>{{ $r['assignment_status'] }}</td>
          <td>{{ $r['task_status'] }}</td>
          <td>{{ $r['quiz_status'] }}</td>
          <td>{{ $r['score'] }}</td>
          <td>{{ $r['date_completed'] }}</td>
          <td>{{ $r['remarks'] }}</td>
        </tr>
      @empty
        <tr><td colspan="10" class="empty">No data for this period.</td></tr>
      @endforelse
    </tbody>
  </table>
@endif

{{-- ── Weekly Teacher Activity Report ── --}}
@if($report_type === 'weekly_activity')
  @forelse(($data['subjects'] ?? []) as $subj)
    <h3>Subject: {{ $subj['subject'] }} @if($subj['grade_level']) ({{ $subj['grade_level'] }}) @endif</h3>
    @foreach($subj['days'] as $i => $day)
      <p style="margin:8px 0 4px;font-weight:bold;color:#6d28d9;">Day {{ $i + 1 }} — {{ $day['day'] }}</p>
      <table class="data">
        <thead>
          <tr>
            <th>Type</th><th>Title</th><th>Description</th><th>Status</th><th>Created</th>
          </tr>
        </thead>
        <tbody>
          @forelse($day['entries'] as $e)
            <tr>
              <td>{{ $e['type'] }}</td>
              <td>{{ $e['title'] }}</td>
              <td>{{ $e['description'] }}</td>
              <td>{{ $e['status'] }}</td>
              <td>{{ $e['created_date'] }}</td>
            </tr>
          @empty
            <tr><td colspan="5" class="empty">No activity created.</td></tr>
          @endforelse
        </tbody>
      </table>
    @endforeach
  @empty
    <p class="empty">No activities recorded for this week.</p>
  @endforelse

  <div class="signature">
    <table>
      <tr>
        <td style="width:50%;">
          <div class="line">{{ $data['teacher']['name'] ?? '' }}</div>
          <p class="role">Teacher — Submitted</p>
        </td>
        <td style="width:50%;">
          <div class="line">&nbsp;</div>
          <p class="role">School Head — Noted</p>
        </td>
      </tr>
    </table>
  </div>
@endif

{{-- ── Subject Report ── --}}
@if($report_type === 'subject_report')
  <h3>Summary</h3>
  <table class="data">
    <tbody>
      <tr><th>Subject</th>     <td>{{ $data['subject']['name'] ?? '' }}</td></tr>
      <tr><th>Grade Level</th> <td>{{ $data['subject']['grade_level'] ?? '' }}</td></tr>
      <tr><th>Teacher</th>     <td>{{ $data['subject']['teacher_name'] ?? '' }}</td></tr>
      <tr><th>Total Students</th>    <td>{{ $data['totals']['total_students'] }}</td></tr>
      @if(empty($data['activity_type']))
        <tr><th>Total Lessons</th>     <td>{{ $data['totals']['total_lessons'] }}</td></tr>
        <tr><th>Total Assignments</th> <td>{{ $data['totals']['total_assignments'] }}</td></tr>
        <tr><th>Total Activities</th>  <td>{{ $data['totals']['total_tasks'] }}</td></tr>
        <tr><th>Total Quizzes</th>     <td>{{ $data['totals']['total_quizzes'] }}</td></tr>
      @elseif($data['activity_type'] === 'Assignment')
        <tr><th>Total Assignments</th> <td>{{ $data['totals']['total_assignments'] }}</td></tr>
      @elseif($data['activity_type'] === 'Activity')
        <tr><th>Total Activities</th>  <td>{{ $data['totals']['total_tasks'] }}</td></tr>
      @else
        <tr><th>Total Quizzes</th>     <td>{{ $data['totals']['total_quizzes'] }}</td></tr>
      @endif
    </tbody>
  </table>

  <h3>Student Progress</h3>
  <table class="data">
    <thead>
      <tr>
        @if(empty($data['activity_type']))
          <th>Student</th><th>Lessons Accessed</th><th>Assignments</th>
          <th>Activities</th><th>Quizzes</th><th>Avg Score</th>
          <th>Status</th><th>Remarks</th>
        @else
          <th>Student</th><th>{{ $data['activity_type'] }} Submitted</th>
          <th>Avg Score</th><th>Status</th><th>Remarks</th>
        @endif
      </tr>
    </thead>
    <tbody>
      @forelse(($data['students'] ?? []) as $s)
        <tr>
          <td>{{ $s['student_name'] }}</td>
          @if(empty($data['activity_type']))
            <td>{{ $s['lessons_accessed'] }}</td>
            <td>{{ $s['assignments_submitted'] }}</td>
            <td>{{ $s['tasks_completed'] }}</td>
            <td>{{ $s['quizzes_taken'] }}</td>
          @elseif($data['activity_type'] === 'Assignment')
            <td>{{ $s['assignments_submitted'] }}</td>
          @elseif($data['activity_type'] === 'Activity')
            <td>{{ $s['tasks_completed'] }}</td>
          @else
            <td>{{ $s['quizzes_taken'] }}</td>
          @endif
          <td>{{ $s['average_score'] }}</td>
          <td>{{ $s['completion_status'] }}</td>
          <td>{{ $s['remarks'] }}</td>
        </tr>
      @empty
        <tr><td colspan="{{ empty($data['activity_type']) ? 8 : 5 }}" class="empty">No students enrolled.</td></tr>
      @endforelse
    </tbody>
  </table>
@endif

{{-- ── Student Report ── --}}
@if($report_type === 'student_report')
  <h3>Activity Summary</h3>
  <table class="data">
    <tbody>
      <tr><th>Student</th> <td>{{ $data['student']['name'] ?? '' }}</td></tr>
      <tr><th>Subject</th> <td>{{ $data['subject']['name'] ?? '' }} — {{ $data['subject']['grade_level'] ?? '' }}</td></tr>
      @if(empty($data['activity_type']))
        <tr><th>Lessons Viewed</th>     <td>{{ $data['lessons']['viewed'] }}</td></tr>
        <tr><th>Lessons Downloaded</th> <td>{{ $data['lessons']['downloaded'] }}</td></tr>
        <tr><th>Assignments Submitted</th> <td>{{ $data['assignments_submitted'] }}</td></tr>
        <tr><th>Activities Completed</th>    <td>{{ $data['tasks_completed'] }}</td></tr>
        <tr><th>Quizzes Taken</th>      <td>{{ $data['quizzes_taken'] }}</td></tr>
      @elseif($data['activity_type'] === 'Assignment')
        <tr><th>Assignments Submitted</th> <td>{{ $data['assignments_submitted'] }}</td></tr>
      @elseif($data['activity_type'] === 'Activity')
        <tr><th>Activities Completed</th> <td>{{ $data['tasks_completed'] }}</td></tr>
      @else
        <tr><th>Quizzes Taken</th> <td>{{ $data['quizzes_taken'] }}</td></tr>
      @endif
    </tbody>
  </table>

  @if(empty($data['activity_type']))
  <h3>Scores</h3>
  <table class="data">
    <thead><tr><th>Quiz Avg</th><th>Assignment Avg</th><th>Task Avg</th><th>Overall Avg</th></tr></thead>
    <tbody>
      <tr>
        <td>{{ $data['scores']['quiz_avg'] }}</td>
        <td>{{ $data['scores']['assignment_avg'] }}</td>
        <td>{{ $data['scores']['task_avg'] }}</td>
        <td>{{ $data['scores']['overall_avg'] }}</td>
      </tr>
    </tbody>
  </table>
  @else
  <h3>{{ $data['activity_type'] }} Average Score</h3>
  <table class="data">
    <thead><tr><th>Average Score</th></tr></thead>
    <tbody>
      @php($scoreKey = $data['activity_type'] === 'Activity' ? 'task_avg' : strtolower($data['activity_type']) . '_avg')
      <tr><td>{{ $data['scores'][$scoreKey] ?? '—' }}</td></tr>
    </tbody>
  </table>
  @endif

  @if(empty($data['activity_type']))
  <h3>Missing Activities</h3>
  @if(count($data['missing'] ?? []))
    <table class="data">
      <thead><tr><th>Title</th><th>Type</th><th>Due Date</th></tr></thead>
      <tbody>
        @foreach($data['missing'] as $m)
          <tr>
            <td>{{ $m['title'] }}</td>
            <td>{{ $m['type'] }}</td>
            <td>{{ $m['due_date'] }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @else
    <p class="empty">No missing activities.</p>
  @endif
  @endif

  {{-- ── Selected Task Score Report ── --}}
  @if($report_type === 'task_report')
    <table class="data">
      <thead>
        <tr><th>Student</th><th>Status</th><th>Score</th><th>Submitted</th></tr>
      </thead>
      <tbody>
        @forelse(($data['rows'] ?? []) as $row)
          <tr>
            <td>{{ $row['student_name'] }}</td>
            <td>{{ $row['status'] }}</td>
            <td>
              @if($row['score'] === null)
                —
              @else
                {{ $row['score'] }}@if(!empty($data['activity']['total_points'])) / {{ $data['activity']['total_points'] }}@endif
              @endif
            </td>
            <td>{{ $row['submitted_at'] ?? '—' }}</td>
          </tr>
        @empty
          <tr><td colspan="4" class="empty">No students enrolled.</td></tr>
        @endforelse
      </tbody>
    </table>
  @endif

  <h3>Status & Remarks</h3>
  <table class="data">
    <tbody>
      <tr><th>Completion Status</th> <td>{{ $data['completion_status'] }}</td></tr>
      <tr><th>Remarks</th>            <td>{{ $data['remarks'] }}</td></tr>
    </tbody>
  </table>
@endif

<div class="footer">
  Panabo SDA Learning Center, Inc. &mdash; Report generated automatically via StudyNest LMS.
</div>

</body>
</html>