<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GradePublished extends Notification implements ShouldQueue
{
    use Queueable;

    protected $grade;
    protected $courseName;

    public function __construct($grade, $courseName)
    {
        $this->grade = $grade;
        $this->courseName = $courseName;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        $grade = $this->grade;
        
        return (new MailMessage)
            ->subject('New Grade Published - ' . config('app.name'))
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('A new grade has been published for: ' . $this->courseName)
            ->line('Grade Details:')
            ->line('• Course: ' . $this->courseName)
            ->line('• Assignment: ' . ($grade->assignment_name ?? 'Final Grade'))
            ->line('• Score: ' . $grade->score . '/' . $grade->total_points)
            ->line('• Percentage: ' . round(($grade->score / $grade->total_points) * 100, 2) . '%')
            ->action('View All Grades', url('/student/grades'))
            ->line('Keep up the great work!');
    }
}
