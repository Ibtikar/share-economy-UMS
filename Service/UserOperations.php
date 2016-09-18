<?php

namespace Ibtikar\ShareEconomyUMSBundle\Service;

use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Ibtikar\ShareEconomyToolsBundle\Service\APIOperations;
use Ibtikar\ShareEconomyUMSBundle\Entity\BaseUser;
use Ibtikar\ShareEconomyUMSBundle\Entity\PhoneVerificationCode;
use Ibtikar\ShareEconomyUMSBundle\APIResponse;

/**
 * @author Mahmoud Mostafa <mahmoud.mostafa@ibtikar.net.sa>
 */
class UserOperations extends APIOperations
{

    /** @var $container ContainerAwareInterface */
    private $container;
    private $configParams;

    /**
     * @param ContainerAwareInterface $container
     */
    public function __construct($container)
    {
        $this->container = $container;
        $this->configParams = $container->getParameter('ibtikar.shareeconomy.ums.parameters');

        parent::__construct($container->getParameter('assets_domain'));
    }

    /**
     * Gets a container service by its id.
     *
     * @param string $id The service id
     *
     * @return object The service
     */
    public function get($id)
    {
        return $this->container->get($id);
    }

    /**
     * Gets a container configuration parameter by its name.
     *
     * @param string $name The parameter name
     *
     * @return mixed
     */
    public function getParameter($name)
    {
        return $this->container->getParameter($name);
    }

    /**
     * @return BaseUser|null
     */
    public function getLoggedInUser()
    {
        $token = $this->container->get('security.token_storage')->getToken();
        if ($token && is_object($token)) {
            $user = $token->getUser();
            if (is_object($user) && $user instanceof BaseUser) {
                return $user;
            }
        }
    }

    /**
     * @param string|null $message
     * @return JsonResponse
     */
    public function getInvalidCredentialsJsonResponse($message = null)
    {
        $errorResponse = new APIResponse\InvalidCredentials();
        if ($message) {
            $errorResponse->message = $message;
        }
        return $this->getJsonResponseForObject($errorResponse);
    }

    /**
     * @param BaseUser $user
     * @return array
     */
    public function getUserData(BaseUser $user)
    {
        $responseUser = $this->getUserObjectResponse($user);
        return $this->getObjectDataAsArray($responseUser);
    }

    /**
     *
     * @param BaseUser $user
     * @return \Ibtikar\ShareEconomyUMSBundle\APIResponse\User
     */
    public function getUserObjectResponse(BaseUser $user)
    {
        $responseUser = new APIResponse\User();
        $responseUser->id = $user->getId();
        $responseUser->fullName = $user->getFullName();
        $responseUser->email = $user->getEmail();
        $responseUser->phone = $user->getPhone();
        $responseUser->emailVerified = $user->getEmailVerified();
        $responseUser->isPhoneVerified = $user->getIsPhoneVerified();
        if ($user->getImage()) {
            $responseUser->image = $this->get('request_stack')->getCurrentRequest()->getSchemeAndHttpHost() . '/' . $user->getWebPath();
        }

        return $responseUser;
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function getLoggedInUserDataJsonResponse(Request $request)
    {
        $loggedInUser = $this->getLoggedInUser();
        if ($loggedInUser) {
            $userData = $this->getUserData($loggedInUser);
            $authorizationHeader = $request->headers->get('Authorization');
            if ($authorizationHeader) {
                $userData['token'] = str_replace('Bearer ', '', $authorizationHeader);
            }
            $loggedInUserResponse = new APIResponse\SuccessLoggedInUser();
            $loggedInUserResponse->user = $userData;
            return $this->getJsonResponseForObject($loggedInUserResponse);
        }
        return $this->getInvalidCredentialsJsonResponse();
    }

    /**
     * @param string $userEmail
     * @return string
     */
    public function sendResetPasswordEmail($userEmail)
    {
        $translator = $this->get('translator');
        if (!$userEmail) {
            return $translator->trans('fill_mandatory_field', array(), 'validators');
        }
        $em = $this->get('doctrine')->getManager();
        $user = $em->getRepository('IbtikarShareEconomyUMSBundle:User')->findOneBy(['email' => $userEmail]);
        if (!$user) {
            return $translator->trans('email_not_registered');
        }
        if (!$this->canRequestForgetPasswordEmail($user)) {
            return $translator->trans('reach_max_forget_password_requests_error');
        }
        $this->generateNewForgetPasswordToken($user);
        $em->flush($user);
        $this->get('ibtikar.shareeconomy.ums.email_sender')->sendResetPasswordEmail($user);
        return 'success';
    }

    /**
     * add new verification code to the user
     *
     * @param User $user
     * @author Karim Shendy <kareem.elshendy@ibtikar.net.sa>
     * @return PhoneVerificationCode
     */
    public function addNewVerificationCode(User $user)
    {
        $phoneVerificationCode = new PhoneVerificationCode();
        $user->addPhoneVerificationCode($phoneVerificationCode);

        return $phoneVerificationCode;
    }

    /**
     * validate object and return error messages array
     *
     * @param type $object
     * @param type $groups
     * @return array
     */
    public function validateObject($object, $groups)
    {
        $validationMessages = [];
        $errors             = $this->get('validator')->validate($object, null, $groups);

        if (count($errors) > 0) {
            foreach ($errors as $error) {
                $validationMessages[$error->getPropertyPath()] = $error->getMessage();
            }
        }

        return $validationMessages;
    }

    /**
     * send phone verification code SMS
     *
     * @param BaseUser $user
     * @param type $code
     * @author Karim Shendy <kareem.elshendy@ibtikar.net.sa>
     * @return boolean
     */
    public function sendVerificationCodeMessage(BaseUser $user, $code)
    {
        try {
            $message = $this->get('translator')->trans('Verification code for %project% is (%code%) valid for %validationTimeInMinutes% minutes', array(
                '%project%' => $this->getParameter('nexmo_from_name'),
                '%code%' => $code->getCode(),
                '%validationTimeInMinutes%' => $this->configParams['verification_code_expiry_minutes']
            ));
            $message = "Verification code for Akly is (".$code->getCode().") valid for ".$this->configParams['verification_code_expiry_minutes']." minutes";
            $this->get('jhg_nexmo_sms')->sendText($user->getPhone(), $message);
            $return  = true;
        } catch (\Exception $ex) {
            $return = false;
        }

        return $return;
    }

    /**
     * check if the user reached the max todays requests
     *
     * @param BaseUser $user
     * @return boolean
     */
    public function canRequestPhoneVerificationCode(BaseUser $user)
    {
        $em         = $this->get('doctrine')->getManager();
        $codesCount = $em->getRepository('IbtikarShareEconomyUMSBundle:PhoneVerificationCode')->countTodaysCodes($user);

        return $codesCount < $this->configParams['max_daily_verification_code_requests'];
    }

    /**
     * @author Mahmoud Mostafa <mahmoud.mostafa@ibtikar.net.sa>
     * @param BaseUser $user
     * @return boolean
     */
    public function verifyUserEmail(BaseUser $user)
    {
        $user->setEmailVerified(true);
        $user->setEmailVerificationToken(null);
        $user->setEmailVerificationTokenExpiryTime(null);
        $this->get('doctrine')->getManager()->flush($user);
        return true;
    }

    /**
     * generate random email verification token
     *
     * @author Karim Shendy <kareem.elshendy@ibtikar.net.sa>
     * @param BaseUser $user
     */
    public function generateNewEmailVerificationToken(BaseUser $user)
    {
        $now = new \DateTime();

        if (null !== $user->getLastEmailVerificationRequestDate() && $user->getLastEmailVerificationRequestDate()->format('Ymd') == $now->format('Ymd')) {
            $user->setVerificationEmailRequests($user->getVerificationEmailRequests() + 1);
        } else {
            $user->setVerificationEmailRequests(1);
            $user->setLastEmailVerificationRequestDate($now);
        }

        $user->setEmailVerificationTokenExpiryTime(new \DateTime('+1 day'));
        $user->setEmailVerificationToken(bin2hex(random_bytes(32)));
    }

    /**
     * generate random forget password token
     *
     * @author Karim Shendy <kareem.elshendy@ibtikar.net.sa>
     * @param BaseUser $user
     */
    public function generateNewForgetPasswordToken(BaseUser $user)
    {
        $now = new \DateTime();

        if (null !== $user->getLastForgetPasswordRequestDate() && $user->getLastForgetPasswordRequestDate()->format('Ymd') == $now->format('Ymd')) {
            $user->setForgetPasswordRequests($user->getForgetPasswordRequests() + 1);
        } else {
            $user->setForgetPasswordRequests(1);
            $user->setLastForgetPasswordRequestDate($now);
        }

        $user->setChangePasswordTokenExpiryTime(new \DateTime('+1 day'));
        $user->setChangePasswordToken(bin2hex(random_bytes(32)));
    }

    /**
     * check the ability of requesting new forget password email
     *
     * @param BaseUser $user
     * @return boolean
     */
    public function canRequestForgetPasswordEmail(BaseUser $user)
    {
        $now    = new \DateTime();
        $return = true;

        if (null !== $user->getLastForgetPasswordRequestDate()) {
            if (($user->getLastForgetPasswordRequestDate()->format('Ymd') == $now->format('Ymd')) && $user->getForgetPasswordRequests() >= $this->configParams['max_daily_forget_passwords_requests']) {
                $return = false;
            }
        }

        return $return;
    }

    /**
     * check the ability of requesting new forget password email
     *
     * @param BaseUser $user
     * @return boolean
     */
    public function canRequestVerificationEmail(BaseUser $user)
    {
        $now    = new \DateTime();
        $return = true;

        if (null !== $user->getLastEmailVerificationRequestDate()) {
            if (($user->getLastEmailVerificationRequestDate()->format('Ymd') == $now->format('Ymd')) && $user->getVerificationEmailRequests() >= $this->configParams['max_daily_forget_passwords_requests']) {
                $return = false;
            }
        }

        return $return;
    }
}
