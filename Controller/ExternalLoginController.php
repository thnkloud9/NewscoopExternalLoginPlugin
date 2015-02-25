<?php

namespace Newscoop\ExternalLoginPluginBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response; 

use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Route("/external_login")
 */ 
class ExternalLoginController extends Controller
{
    /**
     * @Route("/external_login")
     * @Route("/external_login/")
     * @Template()
     */
    public function indexAction(Request $request)
    {
        $em = $this->container->get('em');
        $error = null;
        $languages = $em->getRepository('Newscoop\Entity\Language')->getLanguages();

        // TODO: if we need to check for a token on GET requests
        //       add check here for expected parameter(s).  If token 
        //       does exist, call checkToken in the if
        $requestHasToken = false;
        if ($requestHasToken) {
            return $this->checkToken($request);
        }

        if ($request->isMethod('POST')) {
            return $this->checkToken($request);
        }

        return array(
            'error' => $error,
            'defaultLanguage'   => $this->getDefaultLanguage($request, $languages),
            'languages' => $languages
        );
    }

    private function checkToken($request) {
        $em = $this->container->get('em');
        $baseurl = $request->getScheme() . '://' . $request->getHttpHost();
        $language = $request->request->get('login_language');

        // TODO: fill in the actual parameter to look for the token
        $identity = $request->query->get('openid_identity');
        // TODO: change this to = $identity once ready
        $username = 'admin';

        if (empty($identity)) {
            // TODO: make this var configurable in system preferences
            $redirectUrl = 'https://login.sourcefabric.org/server/simple/login/';
            $redirectUrl .= '?return=' . $baseurl . '/external_login';
            return $this->redirect($redirectUrl);
        } 
        
        $user = $em->getRepository('Newscoop\Entity\User')->findOneByUsername($username);

        $session = $this->container->get('session');
        $firewall = 'admin_area';

        // first do symfony login
        $token = new UsernamePasswordToken($user, $user->getPassword(), $firewall, $user->getRoles());
        $this->get('security.context')->setToken($token); //now the user is logged in
        $session->set('_security_'.$firewall, serialize($token));
        
        // now handle Zend_Auth (blaaaaahhhhhhh...why!)
        $zendAuth = \Zend_Auth::getInstance();
        $authAdapter = $this->container->get('auth.adapter');
        $authAdapter->setUsername($user->getUsername())
            ->setPassword($user->getPassword())
            ->setExternal(true)
            ->setAdmin(true);
        $result = $zendAuth->authenticate($authAdapter);
         
        // and now Oauth
        $session->set('_security_oauth_authorize', serialize($token));

        $user->setLastLogin(new \DateTime());
        $em->flush();

        //now dispatch the login event
        $session->save();
        $event = new InteractiveLoginEvent($request, $token);
        $this->get('event_dispatcher')->dispatch('security.interactive_login', $event);

        $response = new RedirectResponse($baseurl . '/admin/');
        $cookie = new Cookie($session->getName(), $session->getId());
        $response->headers->setCookie($cookie);
 
        return $response;
    }

    private function getDefaultLanguage($request, $languages)
    {
        $defaultLanguage = 'en';

        if ($request->request->has('TOL_Language')) {
            $defaultLanguage = $request->request->get('TOL_Language');
        } elseif ($request->cookies->has('TOL_Language')) {
            $defaultLanguage = $request->cookies->get('TOL_Language');
        } else {
            // Get the browser languages
            $browserLanguageStr = $request->server->get('HTTP_ACCEPT_LANGUAGE', '');
            $browserLanguageArray = preg_split("/[,;]/", $browserLanguageStr);
            $browserLanguagePrefs = array();
            foreach ($browserLanguageArray as $tmpLang) {
                if (!(substr($tmpLang, 0, 2) == 'q=')) {
                    $browserLanguagePrefs[] = $tmpLang;
                }
            }
            // Try to match preference exactly.
            foreach ($browserLanguagePrefs as $pref) {
                if (array_key_exists($pref, $languages)) {
                    $defaultLanguage = $pref;
                    break;
                }
            }
            // Try to match two-letter language code.
            if (is_null($defaultLanguage)) {
                foreach ($browserLanguagePrefs as $pref) {
                    if (substr($pref, 0, 2) != "" && array_key_exists(substr($pref, 0, 2), $languages)) {
                        $defaultLanguage = $pref;
                        break;
                    }
                }
            }

            // HACK: the function regGS() strips off the ":en" from
            // english language strings, but only if it knows that
            // the language being displayed is english...and it knows
            // via the cookie.
            $request->request->set('TOL_Language', $defaultLanguage);
            $request->cookies->set('TOL_Language', $defaultLanguage);
        }

        return $defaultLanguage;
    }
}
