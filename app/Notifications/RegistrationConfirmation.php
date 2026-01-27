<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RegistrationConfirmation extends Notification implements ShouldQueue
{
    use Queueable;

    protected $registration;

    public function __construct($registration)
    {
        $this->registration = $registration;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        $registration = $this->registration;
        
        return (new MailMessage)
            ->subject('Registration Confirmation - ' . config('app.name'))
            ->greeting('Hello ' . $registration->full_name_en . '!')
            ->line('Thank you for registering at ' . config('app.name') . '.')
            ->line('Registration Details:')
            ->line('• Student ID: ' . ($registration->student_code ?? 'Pending'))
            ->line('• Department: ' . ($registration->department_name ?? 'N/A'))
            ->line('• Major: ' . ($registration->major_name ?? 'N/A'))
            ->line('• Academic Year: ' . ($registration->academic_year ?? date('Y')))
            ->action('View Dashboard', url('/student/dashboard'))
            ->line('If you have any questions, please contact the admissions office.')
            ->line('Thank you for choosing us!');
    }
}
