<?php

namespace Ibtikar\ShareEconomyUMSBundle\Service;

use Ibtikar\ShareEconomyUMSBundle\APIResponse\User as ResponseUser;
use Ibtikar\ShareEconomyUMSBundle\Entity\User;

/**
 * @author Mahmoud Mostafa <mahmoud.mostafa@ibtikar.net.sa>
 */
class APIOperations
{

    /**
     * @param object $object
     * @return array
     */
    public function getObjectDataAsArray($object)
    {
        return json_decode(json_encode($object), true);
    }

    /**
     * @param User $user
     * @return array
     */
    public function getUserData(User $user)
    {
        $responseUser = new ResponseUser();
        $responseUser->id = $user->getId();
        $responseUser->fullName = $user->getFullName();
        $responseUser->email = $user->getEmail();
        $responseUser->phone = $user->getPhone();
        $responseUser->emailVerified = $user->getEmailVerified();
        $responseUser->isPhoneVerified = $user->getIsPhoneVerified();
        return $this->getObjectDataAsArray($responseUser);
    }
}