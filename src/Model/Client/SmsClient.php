<?php
/**
 * Project: skebby-bundle
 * User: Leandro Luccerini <leandro.luccerini@gmail.com>
 * Date: 23/05/19
 * Time: 17.27
 */

namespace Szopen\SkebbyBundle\Model\Client;


use GuzzleHttp\Exception\GuzzleException;
use Karriere\JsonDecoder\JsonDecoder;
use Szopen\SkebbyBundle\Exception\AuthenticationException;
use Szopen\SkebbyBundle\Exception\CustomSenderNotAllowedException;
use Szopen\SkebbyBundle\Exception\InvalidInputException;
use Szopen\SkebbyBundle\Exception\InvalidMessageTypeException;
use Szopen\SkebbyBundle\Exception\InvalidOrderIdException;
use Szopen\SkebbyBundle\Exception\InvalidRecipientTypeException;
use Szopen\SkebbyBundle\Exception\MissingParameterException;
use Szopen\SkebbyBundle\Exception\NotFoundException;
use Szopen\SkebbyBundle\Exception\RecipientsNotFoundException;
use Szopen\SkebbyBundle\Exception\UnknownErrorException;
use Szopen\SkebbyBundle\Model\Data\Group;
use Szopen\SkebbyBundle\Model\Data\Recipient;
use Szopen\SkebbyBundle\Model\Data\Sms;
use Szopen\SkebbyBundle\Model\Data\SmsRecipientDeliveryState;
use Szopen\SkebbyBundle\Model\Response\SmsHistorycalResponse;
use Szopen\SkebbyBundle\Model\Response\SmsResponse;
use Szopen\SkebbyBundle\Model\Transformers\SmsResponseTransformer;


/**
 * Class SmsClient
 *
 * @author Leandro Luccerini <leandro.luccerini@gmail.com>
 * @package Szopen\SkebbyBundle\Model\Client
 */
class SmsClient extends AbstractClient
{
    /**
     * @const
     */
    const ACTION_SEND_SMS = 'sms';

    /**
     * @const
     */
    const ACTION_SEND_PARAM_SMS = 'paramsms';

    /**
     * @const
     */
    const ACTION_SEND_GROUP_SMS = 'smstogroups';

    /**
     * @const
     */
    const ACTION_GET_MSG_HISTORY = 'smshistory';


    /**
     * Send an Sms to Recipients identified by a number, one by one.
     * If the message of the Sms contains at least one parameter placeholder ${paramname}, the system decides which
     * endpoint to use.
     *
     * All recipients must be of type Szopen\SkebbyBundle\Model\Data\Recipient otherwise raises
     * an InvalidRecipientTypeException exception
     *
     * @param Sms $sms
     * @param bool $allowInvalidRecipents Sending to an invalid recipient does not block the operation
     * @param bool $returnRemaining
     * @param bool $returnCredits
     *
     * @return mixed
     *
     * @throws CustomSenderNotAllowedException
     * @throws InvalidRecipientTypeException
     * @throws MissingParameterException
     * @throws NotFoundException
     * @throws RecipientsNotFoundException
     * @throws GuzzleException
     * @throws AuthenticationException
     * @throws InvalidInputException
     * @throws InvalidOrderIdException
     * @throws UnknownErrorException
     */
    public function sendSms(Sms $sms,
                            bool $allowInvalidRecipents = false,
                            bool $returnRemaining = false,
                            bool $returnCredits = false): SmsResponse
    {

        // Decides which endpoint to use
        $endopoint = $sms->hasParameters() ? self::ACTION_SEND_PARAM_SMS : self::ACTION_SEND_SMS;

        return $this->send($sms,
            $endopoint,
            false,
            $allowInvalidRecipents,
            $returnRemaining,
            $returnCredits);
    }

    /**
     * Send an Sms to Recipients identified by a groups
     * All recipients must by of type Szopen\SkebbyBundle\Model\Data\Group otherwise raises
     * an InvalidRecipientTypeException exception
     *
     * @param Sms $sms
     * @param bool $allowInvalidRecipents Sending to an invalid recipient does not block the operation
     * @param bool $returnRemaining
     * @param bool $returnCredits
     *
     * @return mixed
     *
     * @throws CustomSenderNotAllowedException
     * @throws InvalidRecipientTypeException
     * @throws MissingParameterException
     * @throws NotFoundException
     * @throws RecipientsNotFoundException
     * @throws GuzzleException
     * @throws AuthenticationException
     * @throws InvalidInputException
     * @throws InvalidOrderIdException
     * @throws UnknownErrorException
     */
    public function sendGroupSms(Sms $sms,
                                 bool $allowInvalidRecipents = false,
                                 bool $returnRemaining = false,
                                 bool $returnCredits = false): SmsResponse
    {
        return $this->send($sms,
            self::ACTION_SEND_GROUP_SMS,
            true,
            $allowInvalidRecipents,
            $returnRemaining,
            $returnCredits);
    }

    /**
     * Get informations on the SMS delivery status of the given $orderId.
     *
     * @param string $orderId
     *
     * @return SmsRecipientDeliveryState[]
     *
     * @throws GuzzleException
     * @throws AuthenticationException
     * @throws InvalidInputException
     * @throws UnknownErrorException
     */
    public function getSmsState(string $orderId): array
    {
        try {
            $endpoint = self::ACTION_SEND_SMS . "/%s";
            $response = $this->executeAction(sprintf($endpoint, $orderId));

            $r = json_decode($response->getBody()->getContents(), true);

            $states = [];

            foreach ($r['recipients'] as $state) {

                $s = new SmsRecipientDeliveryState();
                $s->setRecipient($state['destination']);
                $s->setStatus($state['status']);

                if (!empty($state['delivery_date'])) {
                    $s->setDeliveryDate(\DateTime::createFromFormat("YmdHis", $state['delivery_date']));
                } else {
                    $s->setDeliveryDate(null);
                }

                $states[] = $s;
            }

            return $states;
        } catch (NotFoundException $e) {
            return [];
        }
    }

    /**
     * Deletes the SMS delivery process of the given $orderId.
     *
     * @param string $orderId
     *
     * @return bool
     *
     * @throws GuzzleException
     * @throws AuthenticationException
     * @throws InvalidInputException
     * @throws UnknownErrorException
     */
    public function deleteScheduledDelivery(string $orderId): bool
    {
        try {

            $endpoint = self::ACTION_SEND_SMS . "/%s";
            $this->executeAction(sprintf($endpoint, $orderId), self::ACTION_METHOD_DELETE);

            return true;

        } catch (NotFoundException $e) {
            return false;
        }
    }

    /**
     *  This API is used to retrieve the SMS messages sending history.
     *
     * @param \DateTime $from
     * @param \DateTime|null $to
     * @param int|null $page
     * @param int|null $pageSize
     *
     * @return SmsHistorycalResponse[]
     *
     * @throws NotFoundException
     * @throws GuzzleException
     * @throws AuthenticationException
     * @throws InvalidInputException
     * @throws InvalidMessageTypeException
     * @throws UnknownErrorException
     */
    public function getMessagesHistory(\DateTime $from,
                                       \DateTime $to = null,
                                       int $page = null,
                                       int $pageSize = null): array
    {

        $endpoint = self::ACTION_GET_MSG_HISTORY . "?from=%d";
        $endpoint = sprintf($endpoint, $from->format("YmdHis"));

        if(null !== $to){
            $endpoint .=  "&to=%d";
            $endpoint = sprintf($endpoint, $to->format("YmdHis"));
        }

        if(null !== $page){
            $endpoint .=  "&pageNumber=%d";
            $endpoint = sprintf($endpoint, $page);
        }

        if(null !== $pageSize){
            $endpoint .= "&pageSize=%d";
            $endpoint = sprintf($endpoint, $pageSize);
        }

        $response = $this->executeAction($endpoint);

        $r = json_decode($response->getBody()->getContents(), true);

        $smss = [];

        foreach ($r['smshistory'] as $s){
            $smss[] = new SmsHistorycalResponse($s['order_id'],
                $s['create_time'],
                $s['message_type'],
                $s['num_recipients'],
                $s['sender'],
                $s['schedule_time']);
        }

        return $smss;
    }

    /**
     * @param Sms $sms
     * @param string $endpoint Changes between sms and smstogroups
     * @param bool $groups
     * @param bool $allowInvalidRecipents
     * @param bool $returnRemaining
     * @param bool $returnCredits
     *
     * @return mixed
     *
     * @throws CustomSenderNotAllowedException
     * @throws InvalidRecipientTypeException
     * @throws MissingParameterException
     * @throws NotFoundException
     * @throws RecipientsNotFoundException
     * @throws GuzzleException
     * @throws AuthenticationException
     * @throws InvalidInputException
     * @throws InvalidOrderIdException
     * @throws UnknownErrorException
     */
    protected function send(Sms $sms,
                            string $endpoint,
                            bool $groups,
                            bool $allowInvalidRecipents,
                            bool $returnRemaining,
                            bool $returnCredits): SmsResponse
    {
        $data = $this->prepareRequest($sms, $groups, $allowInvalidRecipents, $returnRemaining, $returnCredits);

        if ($sms->hasParameters()) {
            $response = $this->executeAction($endpoint,
                self::ACTION_METHOD_POST, json_encode($data, JSON_FORCE_OBJECT));
        } else {
            $response = $this->executeAction($endpoint,
                self::ACTION_METHOD_POST, json_encode($data));
        }

        $jsonDecoder = new JsonDecoder(true, true);
        $jsonDecoder->register(new SmsResponseTransformer());
        $r = $response->getBody()->getContents();

        $response = $jsonDecoder->decode($r, SmsResponse::class);
        // Updates Sms
        $sms->setOrderId($response->getOrderId());

        return $response;
    }

    /**
     * Prepares the request to be sent
     *
     * @param Sms $sms
     * @param bool $groups If must parse a recipient of Group type
     * @param bool $allowInvalidRecipents
     * @param bool $returnRemaining
     * @param bool $returnCredits
     *
     * @return array
     *
     * @throws CustomSenderNotAllowedException
     * @throws InvalidRecipientTypeException
     * @throws MissingParameterException
     * @throws RecipientsNotFoundException
     */
    private function prepareRequest(Sms $sms,
                                    bool $groups,
                                    bool $allowInvalidRecipents = false,
                                    bool $returnRemaining = false,
                                    bool $returnCredits = false): array
    {

        if (!$sms->hasRecipients()) {
            throw new RecipientsNotFoundException("No recipients were found in sms");
        }

        $request = ['message_type' => $sms->getMessageType(),
            'message' => $sms->getMessage(),
            'allowInvalidRecipients' => $allowInvalidRecipents,
            'returnCredits' => $returnCredits,
            'returnRemaining' => $returnRemaining,
            'encoding' => $sms->getEncoding(),
        ];

        if ($sms->hasParameters()) {
            $request['recipients'] = $this->prepareRecipients($sms, $groups, true);
        } else {
            $request['recipient'] = $this->prepareRecipients($sms, $groups);
        }

        if ($sms->hasSender()) {

            if ($sms->getMessageType() == Sms::SMS_BASIC_KEY) {
                throw new CustomSenderNotAllowedException(sprintf("With %s type of sms you can't specify the sender", Sms::SMS_BASIC_KEY));
            }

            $request['sender'] = $sms->getSender();
        }

        if (null !== $sms->getDeliveryTime()) {
            $request['scheduled_delivery_time'] = $sms->getDeliveryTime()->format("YmdHis");
        }

        if (!$sms->isEmptyOrderId()) {
            $request['order_id'] = $sms->getOrderId();
        }

        if ($sms->hasCampaignName()) {
            $request['campaign_name'] = $sms->getCampaignName();
        }

        if ($sms->hasShortLinkUrl()) {
            $request['short_link_url'] = $sms->getShortLinkUrl();
        }

        return $request;
    }

    /**
     * Prepares the recipients to be added to the request.
     * If Sms message contains parameters in the form ${paramName}, all the Recipient(s) must contains
     * those parameters, otherwise MissingParameterException will be raised
     *
     * @param Sms $sms
     * @param bool $groups If must parse a recipient of Group type
     * @param bool $parameters
     *
     * @return array
     *
     * @throws InvalidRecipientTypeException
     * @throws MissingParameterException
     */
    private function prepareRecipients(Sms $sms, bool $groups, bool $parameters = false): array
    {

        $recipients = $sms->getRecipients();

        $returnRecipients = [];

        if ($parameters && !$groups) {

            $smsParameters = $sms->getParameters();

            foreach ($recipients as $r) {
                foreach ($smsParameters as $p) {
                    if (!$r->hasVariable($p)) {
                        $message = "Recipient %s must contain parameter '%s'";
                        throw new MissingParameterException(sprintf($message, $r->getRecipient(), $p));
                    }
                }
                $returnRecipients[] = $r->toArray();
            }

        } else {
            foreach ($recipients as $r) {
                if ($groups && !($r instanceof Group)) {
                    $message = "Recipient %s must be an instance of %s";
                    throw new InvalidRecipientTypeException(sprintf($message, $r->getRecipient(), Group::class));
                } elseif (!$groups && !($r instanceof Recipient)) {
                    $message = "Recipient %s must be an instance of %s";
                    throw new InvalidRecipientTypeException(sprintf($message, $r->getRecipient(), Recipient::class));
                }
                $returnRecipients[] = $r->getRecipient();
            }
        }

        return $returnRecipients;
    }
}