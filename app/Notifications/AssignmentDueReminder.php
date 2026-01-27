<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Carbon\Carbon;

class AssignmentDueReminder extends Notification implements ShouldQueue
{
    use Queueable;

    protected $assignment;
    protected $courseName;

    public function __construct($assignment, $courseName)
    {
        $this->assignment = $assignment;
        $this->courseName = $courseName;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        $assignment = $this->assignment;
        $dueDate = Carbon::parse($assignment->due_date);
        $now = Carbon::now();
        $hoursRemaining = $now->diffInHours($dueDate, false);
        
        return (new MailMessage)
            ->subject('Assignment Due Soon - ' . config('app.name'))
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('Reminder: You have an upcoming assignment deadline.')
            ->line('Assignment Details:')
            ->line('• Course: ' . $this->courseName)
            ->line('• Title: ' . $assignment->title)
            ->line('• Due Date: ' . $dueDate->format('M d, Y') . ' at ' . ($assignment->due_time ?? '23:59'))
            ->line('• Time Remaining: ' . ($hoursRemaining > 0 ? round($hoursRemaining) . ' hours' : 'OVERDUE'))
            ->line('• Points: ' . ($assignment->points ?? 'N/A'))
            ->action('View Assignment', url('/student/assignments/' . $assignment->id))
            ->line('Don\'t forget to submit your work before the deadline!');
    }
}
