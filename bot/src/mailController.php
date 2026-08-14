<?php

namespace PegelBot;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class mailController extends AboController
{
    public function name(): string
    {
        return 'mail';
    }

    public function postNotify(array $abo_details, string $message_content): void
    {
        $this->_logger->debug("[Mail] postNotify()", ['receiver' => $abo_details['email']]);
        $this->sendMail($abo_details['email'], 'Neuer Pegelstand', $message_content);
    }

    public function postTrend(array $abo_details, string $message_content, string $image): void
    {
        $this->_logger->debug("[Mail] postTrend()", ['receiver' => $abo_details['email']]);
        $this->sendMail($abo_details['email'], 'Neue Ganglinie', $message_content, $image);
    }

    private function sendMail(string $receiver, string $subject, string $message, ?string $image = null): void
    {
        $this->_logger->debug("[Mail] sendMail()", [
            'receiver'   => $receiver,
            'subject'    => $subject,
            'has_image'  => !is_null($image),
        ]);

        $mail = new PHPMailer(true);

        try {
            $mail->setFrom('joerg@wasserstrassenkreuz.de', 'Pegelbot');
            $mail->addAddress($receiver);
            $mail->Subject = $subject;
            $mail->Body    = $message;

            if (!is_null($image)) {
                $mail->addStringAttachment($image, 'Ganglinie.png');
                $this->_logger->debug("[Mail] Anhang hinzugefügt", ['filename' => 'Ganglinie.png']);
            }

            $mail->send();

            $this->_logger->info("[Mail] E-Mail erfolgreich versandt", [
                'receiver' => $receiver,
                'subject'  => $subject,
            ]);
            echo "  [Mail] E-Mail an {$receiver} versandt\n";

        } catch (Exception $e) {
            $this->_logger->error("[Mail] Fehler beim Versand", [
                'receiver'   => $receiver,
                'subject'    => $subject,
                'error_info' => $mail->ErrorInfo,
                'exception'  => $e->getMessage(),
            ]);
            echo "  [Mail] Fehler bei E-Mail an {$receiver}: {$mail->ErrorInfo}\n";
            throw $e;
        }
    }
}