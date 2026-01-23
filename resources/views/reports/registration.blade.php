<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Registration Report</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
      font-family: 'DejaVu Sans', sans-serif;
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
    .stat-box:not(:last-child) { border-right: none; }
    .stat-value { font-size: 18px; font-weight: bold; color: #1e40af; display: block; margin-bottom: 3px; }
    .stat-label { font-size: 8px; color: #6b7280; text-transform: uppercase; }

    table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
    th {
      background: #1e40af;
      color: white;
      padding: 8px 5px;
      text-align: left;
      font-size: 9px;
      font-weight: bold;
      text-transform: uppercase;
    }
    td { padding: 6px 5px; border-bottom: 1px solid #e5e7eb; font-size: 9px; }
    tr:nth-child(even) { background: #f9fafb; }

    .badge {
      display: inline-block;
      padding: 2px 6px;
      border-radius: 3px;
      font-size: 8px;
      font-weight: bold;
    }

    .badge-pending { background: #fef3c7; color: #92400e; }
    .badge-paid { background: #d1fae5; color: #065f46; }
    .badge-failed { background: #fee2e2; color: #991b1b; }
    .badge-unknown { background: #e5e7eb; color: #374151; }

    .badge-male { background: #dbeafe; color: #1e40af; }
    .badge-female { background: #fce7f3; color: #9f1239; }

    .footer {
      margin-top: 20px;
      padding-top: 10px;
      border-top: 2px solid #e5e7eb;
      text-align: center;
      font-size: 8px;
      color: #6b7280;
    }

    .text-right { text-align: right; }
  </style>
</head>

<body>
  <div class="header">
    <h1>STUDENT REGISTRATION REPORT</h1>
    <p>Generated on {{ $generated_date }}</p>
  </div>

  <div class="info-section">
    <div class="info-row">
      <span class="info-label">Report Period:</span>
      @if(!empty($filters['date_from']) || !empty($filters['date_to']))
        {{ $filters['date_from'] ?? 'All' }} to {{ $filters['date_to'] ?? 'All' }}
      @else
        All Time
      @endif
    </div>

    @if(!empty($filters['semester']))
      <div class="info-row"><span class="info-label">Semester:</span> Sem {{ $filters['semester'] }}</div>
    @endif
    @if(!empty($filters['academic_year']))
      <div class="info-row"><span class="info-label">Academic Year:</span> {{ $filters['academic_year'] }}</div>
    @endif
    @if(!empty($filters['department_id']))
      <div class="info-row"><span class="info-label">Department Filter:</span> Applied</div>
    @endif
    @if(!empty($filters['major_id']))
      <div class="info-row"><span class="info-label">Major Filter:</span> Applied</div>
    @endif
    @if(!empty($filters['payment_status']))
      <div class="info-row"><span class="info-label">Payment Status:</span> {{ $filters['payment_status'] }}</div>
    @endif
    @if(!empty($filters['shift']))
      <div class="info-row"><span class="info-label">Shift:</span> {{ $filters['shift'] }}</div>
    @endif
    @if(!empty($filters['gender']))
      <div class="info-row"><span class="info-label">Gender:</span> {{ $filters['gender'] }}</div>
    @endif
  </div>

  <div class="stats-grid">
    <div class="stat-box">
      <span class="stat-value">{{ $stats['total_registrations'] ?? 0 }}</span>
      <span class="stat-label">Total Registrations</span>
    </div>
    <div class="stat-box">
      <span class="stat-value">{{ $stats['total_male'] ?? 0 }} / {{ $stats['total_female'] ?? 0 }}</span>
      <span class="stat-label">Male / Female</span>
    </div>
    <div class="stat-box">
      <span class="stat-value">${{ number_format($stats['total_amount'] ?? 0, 2) }}</span>
      <span class="stat-label">Total Amount</span>
    </div>
    <div class="stat-box">
      <span class="stat-value">${{ number_format($stats['paid_amount'] ?? 0, 2) }}</span>
      <span class="stat-label">Paid Amount</span>
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width: 3%;">#</th>
        <th style="width: 10%;">Student Code</th>
        <th style="width: 16%;">Full Name</th>
        <th style="width: 5%;">Gender</th>
        <th style="width: 13%;">Department</th>
        <th style="width: 13%;">Major</th>
        <th style="width: 7%;">Shift</th>
        <th style="width: 10%;">Academic Year</th>
        <th style="width: 6%;">Semester</th>
        <th style="width: 10%;">Payment</th>
        <th style="width: 7%;" class="text-right">Amount</th>
        <th style="width: 10%;">Date</th>
      </tr>
    </thead>

    <tbody>
      @foreach($registrations as $index => $reg)
        @php
          // ✅ NEW FLOW FIRST (student_academic_periods join) then fallback old registration fields
          $statusRaw = $reg->period_payment_status ?? ($reg->payment_status ?? '');
          $status = strtoupper(trim((string)$statusRaw));

          $amountRaw = $reg->period_tuition_amount ?? ($reg->payment_amount ?? 0);
          $amount = (float)($amountRaw ?? 0);

          $year = $reg->period_academic_year ?? ($reg->academic_year ?? null);
          $sem  = $reg->period_semester ?? ($reg->semester ?? ($filters['semester'] ?? null));

          $badgeClass = match ($status) {
            'PENDING', 'UNPAID' => 'badge-pending',
            'PAID', 'COMPLETED', 'SUCCESS' => 'badge-paid',
            'FAILED' => 'badge-failed',
            default => 'badge-unknown',
          };

          $genderRaw = $reg->gender ?? '';
          $gender = ucfirst(strtolower(trim((string)$genderRaw)));
          $genderBadge = ($gender === 'Male') ? 'badge-male' : (($gender === 'Female') ? 'badge-female' : 'badge-unknown');

          $suffixParts = [];
          if (!empty($year)) $suffixParts[] = $year;
          if (!empty($sem)) $suffixParts[] = 'Sem ' . $sem;
          $suffix = implode(' • ', $suffixParts);

          $label = in_array($status, ['PAID','COMPLETED','SUCCESS'], true)
            ? ('PAID' . ($suffix ? ' (' . $suffix . ')' : ''))
            : ($status === 'FAILED'
                ? ('FAILED' . ($suffix ? ' (' . $suffix . ')' : ''))
                : ('PENDING' . ($suffix ? ' (' . $suffix . ')' : '')));

          $deptName = $reg->department_name ?? ($reg->department->department_name ?? ($reg->department->name ?? 'N/A'));
          $majorName = $reg->major_name ?? ($reg->major->major_name ?? 'N/A');
          $studentCode = $reg->student_code ?? ($reg->student->student_code ?? 'N/A');
          $fullName = $reg->full_name_en ?? ($reg->student_full_name ?? 'N/A');
        @endphp

        <tr>
          <td>{{ $index + 1 }}</td>
          <td>{{ $studentCode }}</td>
          <td>{{ $fullName }}</td>
          <td><span class="badge {{ $genderBadge }}">{{ $gender ?: 'N/A' }}</span></td>
          <td>{{ $deptName }}</td>
          <td>{{ $majorName }}</td>
          <td>{{ $reg->shift ?? '-' }}</td>
          <td>{{ $year ?? 'N/A' }}</td>
          <td>{{ $sem ? 'Sem ' . $sem : 'N/A' }}</td>
          <td><span class="badge {{ $badgeClass }}">{{ $label }}</span></td>
          <td class="text-right">${{ number_format($amount, 2) }}</td>
          <td>{{ $reg->created_at ? \Carbon\Carbon::parse($reg->created_at)->format('Y-m-d') : 'N/A' }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <div class="info-section">
    <div class="info-row"><span class="info-label">Payment Pending:</span> {{ $stats['payment_pending'] ?? 0 }} registrations</div>
    <div class="info-row"><span class="info-label">Payment Completed:</span> {{ $stats['payment_completed'] ?? 0 }} registrations</div>
    <div class="info-row">
      <span class="info-label">Collection Rate:</span>
      @php
        $totalAmount = (float)($stats['total_amount'] ?? 0);
        $paidAmount  = (float)($stats['paid_amount'] ?? 0);
        $rate = $totalAmount > 0 ? ($paidAmount / $totalAmount) * 100 : 0;
      @endphp
      {{ number_format($rate, 2) }}%
    </div>
  </div>

  <div class="footer">
    <p>This is a computer-generated report. No signature required.</p>
    <p>&copy; {{ date('Y') }} Nova Tech University - Registration Management System</p>
  </div>
</body>
</html>
