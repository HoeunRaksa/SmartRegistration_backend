<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Registration Report</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
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
        
        .header h1 {
            font-size: 20px;
            color: #1e40af;
            margin-bottom: 5px;
        }
        
        .header p {
            font-size: 9px;
            color: #6b7280;
        }
        
        .info-section {
            background: #f3f4f6;
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 5px;
        }
        
        .info-row {
            display: inline-block;
            margin-right: 20px;
            margin-bottom: 5px;
        }
        
        .info-label {
            font-weight: bold;
            color: #4b5563;
        }
        
        .stats-grid {
            display: table;
            width: 100%;
            margin-bottom: 15px;
        }
        
        .stat-box {
            display: table-cell;
            width: 25%;
            padding: 10px;
            text-align: center;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
        }
        
        .stat-box:not(:last-child) {
            border-right: none;
        }
        
        .stat-value {
            font-size: 18px;
            font-weight: bold;
            color: #1e40af;
            display: block;
            margin-bottom: 3px;
        }
        
        .stat-label {
            font-size: 8px;
            color: #6b7280;
            text-transform: uppercase;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        th {
            background: #1e40af;
            color: white;
            padding: 8px 5px;
            text-align: left;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        td {
            padding: 6px 5px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 9px;
        }
        
        tr:nth-child(even) {
            background: #f9fafb;
        }
        
        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 8px;
            font-weight: bold;
        }
        
        .badge-pending {
            background: #fef3c7;
            color: #92400e;
        }
        
        .badge-completed {
            background: #d1fae5;
            color: #065f46;
        }
        
        .badge-failed {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .badge-male {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .badge-female {
            background: #fce7f3;
            color: #9f1239;
        }
        
        .footer {
            margin-top: 20px;
            padding-top: 10px;
            border-top: 2px solid #e5e7eb;
            text-align: center;
            font-size: 8px;
            color: #6b7280;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        .page-break {
            page-break-after: always;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <h1>STUDENT REGISTRATION REPORT</h1>
        <p>Generated on {{ $generated_date }}</p>
    </div>

    <!-- Filter Information -->
    <div class="info-section">
        <div class="info-row">
            <span class="info-label">Report Period:</span>
            @if(isset($filters['date_from']) || isset($filters['date_to']))
                {{ $filters['date_from'] ?? 'All' }} to {{ $filters['date_to'] ?? 'All' }}
            @else
                All Time
            @endif
        </div>
        
        @if(isset($filters['academic_year']))
        <div class="info-row">
            <span class="info-label">Academic Year:</span> {{ $filters['academic_year'] }}
        </div>
        @endif
        
        @if(isset($filters['department_id']))
        <div class="info-row">
            <span class="info-label">Department Filter:</span> Applied
        </div>
        @endif
        
        @if(isset($filters['major_id']))
        <div class="info-row">
            <span class="info-label">Major Filter:</span> Applied
        </div>
        @endif
        
        @if(isset($filters['payment_status']))
        <div class="info-row">
            <span class="info-label">Payment Status:</span> {{ $filters['payment_status'] }}
        </div>
        @endif
    </div>

    <!-- Statistics Summary -->
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

    <!-- Registration Details Table -->
    <table>
        <thead>
            <tr>
                <th style="width: 3%;">#</th>
                <th style="width: 12%;">Student Code</th>
                <th style="width: 18%;">Full Name</th>
                <th style="width: 5%;">Gender</th>
                <th style="width: 15%;">Department</th>
                <th style="width: 15%;">Major</th>
                <th style="width: 8%;">Shift</th>
                <th style="width: 10%;">Payment</th>
                <th style="width: 8%;" class="text-right">Amount</th>
                <th style="width: 10%;">Date</th>
            </tr>
        </thead>
        <tbody>
            @foreach($registrations as $index => $reg)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $reg->student->student_code ?? 'N/A' }}</td>
                <td>{{ $reg->full_name_en }}</td>
                <td>
                    <span class="badge {{ $reg->gender == 'Male' ? 'badge-male' : 'badge-female' }}">
                        {{ $reg->gender }}
                    </span>
                </td>
                <td>{{ $reg->department->name ?? 'N/A' }}</td>
                <td>{{ $reg->major->major_name ?? 'N/A' }}</td>
                <td>{{ $reg->shift ?? '-' }}</td>
                <td>
                    <span class="badge badge-{{ strtolower($reg->payment_status) }}">
                        {{ $reg->payment_status }}
                    </span>
                </td>
                <td class="text-right">${{ number_format($reg->payment_amount, 2) }}</td>
                <td>{{ \Carbon\Carbon::parse($reg->created_at)->format('Y-m-d') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <!-- Summary at Bottom -->
    <div class="info-section">
        <div class="info-row">
            <span class="info-label">Payment Pending:</span> {{ $stats['payment_pending'] }} registrations
        </div>
        <div class="info-row">
            <span class="info-label">Payment Completed:</span> {{ $stats['payment_completed'] }} registrations
        </div>
        <div class="info-row">
            <span class="info-label">Collection Rate:</span> 
            {{ $stats['total_registrations'] > 0 ? number_format(($stats['paid_amount'] / $stats['total_amount']) * 100, 2) : 0 }}%
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        <p>This is a computer-generated report. No signature required.</p>
        <p>&copy; {{ date('Y') }} Nova Tech University - Registration Management System</p>
    </div>
</body>
</html>