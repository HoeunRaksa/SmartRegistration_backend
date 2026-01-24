<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Registration Report</title>

  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
      font-family: DejaVu Sans, sans-serif;
      font-size: 10px;
      color: #333;
      line-height: 1.4;
    }

    .header {
      text-align: center;
      margin-bottom: 20px;
      padding-bottom: 10px;
      border-bottom: 3px solid #2563eb;
    }
    .header h1 { font-size: 20px; color: #1e40af; margin-bottom: 5px; }
    .header p { font-size: 9px; color: #6b7280; }

    .info-section {
      background: #f3f4f6;
      padding: 10px;
      margin-bottom: 15px;
      border-radius: 5px;
    }

    .info-row { display: inline-block; margin-right: 20px; margin-bottom: 5px; }
    .info-label { font-weight: bold; color: #4b5563; }

    .stats-grid { display: table; width: 100%; margin-bottom: 15px; }
    .stat-box {
      display: table-cell;
      width: 25%;
      padding: 10px;
      text-align: center;
      background: #eff6ff;
      border: 1px solid #bfdbfe;
    }

    .stat-value { font-size: 18px; font-weight: bold; color: #1e40af; }
    .stat-label { font-size: 8px; color: #6b7280; text-transform: uppercase; }

    table { width: 100%; border-collapse: collapse; }
    th {
      background: #1e40af;
      color: white;
      padding: 8px 5px;
      font-size: 9px;
      text-transform: uppercase;
    }
    td { padding: 6px 5px; border-bottom: 1px solid #e5e7eb; font-size: 9px; }
    tr:nth-child(even) { background: #f9fafb; }

    .badge { padding: 2px 6px; border-radius: 3px; font-size: 8px; font-weight: bold; }

    .badge-paid { background: #d1fae5; color: #065f46; }
    .badge-partial { background: #e0e7ff; color: #3730a3; }
    .badge-pending { background: #fef3c7; color: #92400e; }
    .badge-failed { background: #fee2e2; color: #991b1b; }
    .badge-unknown { background: #e5e7eb; color: #374151; }

    .badge-male { background: #dbeafe; color: #1e40af; }
    .badge-female { background: #fce7f3; color: #9f1239; }

    .text-right { text-align: right; }

    .footer {
      margin-top: 20px;
      padding-top: 10px;
      border-top: 2px solid #e5e7eb;
      text-align: center;
      font-size: 8px;
      color: #6b7280;
    }
  </style>
</head>

<body>

{{-- HEADER --}}
<div class="header">
  <h1>STUDENT REGISTRATION REPORT</h1>
  <p>Generated on {{ $generated_date }}</p>
</div>

{{-- FILTER INFO --}}
<div class="info-section">
  <div class="info-row">
    <span class="info-label">Period:</span>
    {{ $filters['date_from'] ?? 'All' }} → {{ $filters['date_to'] ?? 'All' }}
  </div>

  @if(!empty($filters['academic_year']))
    <div class="info-row">
      <span class="info-label">Academic Year:</span> {{ $filters['academic_year'] }}
    </div>
  @endif

  @if(isset($semester))
    <div class="info-row">
      <span class="info-label">Semester:</span>
      {{ $semester == 0 ? 'Full Year' : 'Semester ' . $semester }}
    </div>
  @endif
</div>

{{-- STATS --}}
<div class="stats-grid">
  <div class="stat-box">
    <span class="stat-value">{{ $stats['total_registrations'] }}</span>
    <span class="stat-label">Total Registrations</span>
  </div>
  <div class="stat-box">
    <span class="stat-value">{{ $stats['total_male'] }} / {{ $stats['total_female'] }}</span>
    <span class="stat-label">Male / Female</span>
  </div>
  <div class="stat-box">
    <span class="stat-value">${{ number_format($stats['total_amount'], 2) }}</span>
    <span class="stat-label">Total Amount</span>
  </div>
  <div class="stat-box">
    <span class="stat-value">${{ number_format($stats['paid_amount'], 2) }}</span>
    <span class="stat-label">Paid Amount</span>
  </div>
</div>

{{-- TABLE --}}
<table>
  <thead>
    <tr>
      <th>#</th>
      <th>Student Code</th>
      <th>Full Name</th>
      <th>Gender</th>
      <th>Department</th>
      <th>Major</th>
      <th>Shift</th>
      <th>Academic Year</th>
      <th>Semester</th>
      <th>Payment</th>
      <th class="text-right">Amount</th>
      <th>Date</th>
    </tr>
  </thead>

  <tbody>
  @foreach($registrations as $i => $r)
    @php
      $sem1 = strtoupper($r->sem1_payment_status ?? 'PENDING');
      $sem2 = strtoupper($r->sem2_payment_status ?? 'PENDING');
      $yearStatus = strtoupper($r->year_payment_status ?? 'PENDING');

      // FINAL RULE
      if ($yearStatus === 'PAID') {
        $paymentLabel = 'FULL YEAR PAID';
        $badgeClass = 'badge-paid';
        $semesterText = 'Full Year';
      } elseif ($sem1 === 'PAID' || $sem2 === 'PAID') {
        $paidSem = $sem1 === 'PAID' ? 'Sem 1' : 'Sem 2';
        $paymentLabel = 'PARTIAL (' . $paidSem . ')';
        $badgeClass = 'badge-partial';
        $semesterText = $paidSem;
      } else {
        $paymentLabel = 'PENDING';
        $badgeClass = 'badge-pending';
        $semesterText = '—';
      }

      $amount =
        ($yearStatus === 'PAID')
          ? (float)(($r->sem1_tuition_amount ?? 0) + ($r->sem2_tuition_amount ?? 0))
          : (float)($r->sem1_tuition_amount ?? $r->sem2_tuition_amount ?? 0);
    @endphp

    <tr>
      <td>{{ $i + 1 }}</td>
      <td>{{ $r->student_code ?? 'N/A' }}</td>
      <td>{{ $r->full_name_en ?? 'N/A' }}</td>
      <td>
        <span class="badge {{ strtolower($r->gender) === 'male' ? 'badge-male' : 'badge-female' }}">
          {{ ucfirst($r->gender ?? 'N/A') }}
        </span>
      </td>
      <td>{{ $r->department_name ?? 'N/A' }}</td>
      <td>{{ $r->major_name ?? 'N/A' }}</td>
      <td>{{ $r->shift ?? '-' }}</td>
      <td>{{ $r->report_academic_year ?? 'N/A' }}</td>
      <td>{{ $semesterText }}</td>
      <td><span class="badge {{ $badgeClass }}">{{ $paymentLabel }}</span></td>
      <td class="text-right">${{ number_format($amount, 2) }}</td>
      <td>{{ substr($r->created_at, 0, 10) }}</td>
    </tr>
  @endforeach
  </tbody>
</table>

{{-- FOOTER --}}
<div class="footer">
  <p>This is a system-generated report.</p>
  <p>&copy; {{ date('Y') }} Nova Tech University</p>
</div>

</body>
</html>
