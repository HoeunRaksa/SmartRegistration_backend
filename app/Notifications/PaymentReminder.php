<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentReminder extends Notification implements ShouldQueue
{
    use Queueable;

    protected $registration;
    protected $amount;
    protected $semester;

    public function __construct($registration, $amount, $semester = 1)
    {
        $this->registration = $registration;
        $this->amount = $amount;
        $this->semester = $semester;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        $registration = $this->registration;
        
        return (new MailMessage)
            ->subject('Payment Reminder - ' . config('app.name'))
            ->greeting('Hello ' . $registration->full_name_en . '!')
            ->line('This is a friendly reminder about your pending tuition payment.')
            ->line('Payment Details:')
            ->line('• Amount Due: $' . number_format($this->amount, 2))
            ->line('• Semester: ' . $this->semester)
            ->line('• Academic Year: ' . ($registration->academic_year ?? date('Y')))
            ->action('Pay Now', url('/student/payment'))
            ->line('Please complete your payment to avoid registration holds.')
            ->line('If you have already paid, please disregard this message.');
    }
}
