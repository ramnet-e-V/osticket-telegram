<?php

require_once(INCLUDE_DIR . 'class.signal.php');
require_once(INCLUDE_DIR . 'class.plugin.php');
require_once('config.php');

class TelegramPlugin extends Plugin
{
    var $config_class = "TelegramPluginConfig";

    static $pluginInstance = null;

    private function getPluginInstance(?int $id)
    {
        if ($id && ($i = $this->getInstance($id)))
            return $i;

        return $this->getInstances()->first();
    }

    /**
     * The entrypoint of the plugin, keep short, always runs.
     */
    function bootstrap()
    {
        self::$pluginInstance = self::getPluginInstance(null);
        Signal::connect('ticket.created', [$this, 'onTicketCreated'], 'Ticket');
    }

    /**
     * What to do with a new Ticket?
     *
     * @global OsticketConfig $cfg
     * @param Ticket $ticket
     * @return type
     */
    function onTicketCreated($ticket)
    {
        global $ost;
        global $cfg;
        if (!$cfg instanceof OsticketConfig) {
            error_log("Whatsfy plugin called too early.");
            return;
        }

        $ticketLink = $ost->getConfig()->getUrl() . 'scp/tickets.php?id=' . $ticket->getId();
        $ticketId = $ticket->getNumber();
        $title = $ticket->getSubject() ?: 'No subject';
        $createdBy = $ticket->getName() . " (" . $ticket->getEmail() . ")";
        $chatid = $this->getConfig(self::$pluginInstance)->get('telegram-chat-id');
        $chatid = is_numeric($chatid) ? "-" . $chatid : "@" . $chatid;

        if ($this->getConfig(self::$pluginInstance)->get('telegram-include-body')) {
            $body = $ticket->getLastMessage()->getMessage() ?: 'No content';
            $body = str_replace('<p>', '', $body);
            $body = str_replace('</p>', '<br />', $body);
            $breaks = ["<br />", "<br>", "<br/>"];
            $body = str_ireplace($breaks, "\n", $body);
            $body = preg_replace('/\v(?:[\v\h]+)/', '', $body);
            $body = strip_tags($body);
        }

        $this->sendToTelegram(
            [
                "method" => "sendMessage",
                "chat_id" => $chatid,
                "text" => "<b>New Ticket:</b> <a href=\"" . $ticketLink . "\">#" . $ticketId . "</a>\n<b>Created by:</b> " . $createdBy . "\n<b>Subject:</b> " . $title . ($body ? "\n<b>Message:</b>\n" . $body : ''),
                "parse_mode" => "html",
                "disable_web_page_preview" => "True"
            ]
        );
    }

    function sendToTelegram(array $payload)
    {
        try {
            global $ost;

            $data_string = utf8_encode(json_encode($payload));
            $url = $this->getConfig(self::$pluginInstance)->get('telegram-webhook-url');

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt(
                $ch,
                CURLOPT_HTTPHEADER,
                [
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($data_string)
                ]
            );

            $result = curl_exec($ch);
            if ($result === false) {
                throw new Exception($url . ' - ' . curl_error($ch));
            } else {
                $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                if ($statusCode != '200') {
                    throw new Exception($url . ' Http code: ' . $statusCode);
                }
            }

            curl_close($ch);
            if ($this->getConfig(self::$pluginInstance)->get('debug')) {
                error_log($result);
            }
        } catch (Exception $e) {
            error_log('Error posting to Telegram. ' . $e->getMessage());
            error_log(json_encode($payload));
        }
    }

    function escapeText($text)
    {
        $text = str_replace('&', '&amp;', $text);
        $text = str_replace('<', '&lt;', $text);
        $text = str_replace('>', '&gt;', $text);

        return $text;
    }
}