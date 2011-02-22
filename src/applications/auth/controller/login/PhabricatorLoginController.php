<?php

/*
 * Copyright 2011 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

class PhabricatorLoginController extends PhabricatorAuthController {

  public function shouldRequireLogin() {
    return false;
  }

  public function processRequest() {
    $request = $this->getRequest();

    if ($request->getUser()->getPHID()) {
      // Kick the user out if they're already logged in.
      return id(new AphrontRedirectResponse())->setURI('/');
    }

    $error = false;
    $username = $request->getCookie('phusr');
    if ($request->isFormPost()) {
      $username = $request->getStr('username');

      $user = id(new PhabricatorUser())->loadOneWhere(
        'username = %s',
        $username);

      $okay = false;
      if ($user) {
        if ($user->comparePassword($request->getStr('password'))) {

          $session_key = $user->establishSession('web');

          $request->setCookie('phusr', $user->getUsername());
          $request->setCookie('phsid', $session_key);

          return id(new AphrontRedirectResponse())
            ->setURI('/');
        }
      }

      if (!$okay) {
        $request->clearCookie('phusr');
        $request->clearCookie('phsid');
      }

      $error = true;
    }

    $error_view = null;
    if ($error) {
      $error_view = new AphrontErrorView();
      $error_view->setTitle('Bad username/password.');
    }

    $form = new AphrontFormView();
    $form
      ->setUser($request->getUser())
      ->setAction('/login/')
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel('Username/Email')
          ->setName('username')
          ->setValue($username))
      ->appendChild(
        id(new AphrontFormPasswordControl())
          ->setLabel('Password')
          ->setName('password')
          ->setCaption(
            '<a href="/login/email/">Forgot your password? / Email Login</a>'))
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->setValue('Login'));


    $panel = new AphrontPanelView();
    $panel->setHeader('Phabricator Login');
    $panel->setWidth(AphrontPanelView::WIDTH_FORM);
//    $panel->setCreateButton('Register New Account', '/login/register/');
    $panel->appendChild($form);

    $providers = array(
      PhabricatorOAuthProvider::PROVIDER_FACEBOOK,
      PhabricatorOAuthProvider::PROVIDER_GITHUB,
    );
    foreach ($providers as $provider_key) {
      $provider = PhabricatorOAuthProvider::newProvider($provider_key);

      $enabled = $provider->isProviderEnabled();
      if (!$enabled) {
        continue;
      }

      $auth_uri       = $provider->getAuthURI();
      $redirect_uri   = $provider->getRedirectURI();
      $client_id      = $provider->getClientID();
      $provider_name  = $provider->getProviderName();

      // TODO: In theory we should use 'state' to prevent CSRF, but the total
      // effect of the CSRF attack is that an attacker can cause a user to login
      // to Phabricator if they're already logged into some OAuth provider. This
      // does not seem like the most severe threat in the world, and generating
      // CSRF for logged-out users is vaugely tricky.

      $auth_form = new AphrontFormView();
      $auth_form
        ->setAction($auth_uri)
        ->addHiddenInput('client_id', $client_id)
        ->addHiddenInput('redirect_uri', $redirect_uri)
        ->setUser($request->getUser())
        ->setMethod('GET')
        ->appendChild(
          '<p class="aphront-form-instructions">Login or register for '.
          'Phabricator using your '.$provider_name.' account.</p>')
        ->appendChild(
          id(new AphrontFormSubmitControl())
            ->setValue("Login with {$provider_name} \xC2\xBB"));

      $panel->appendChild(
          '<br /><h1>Login or Register with '.$provider_name.'</h1>');

      $panel->appendChild($auth_form);
    }

    return $this->buildStandardPageResponse(
      array(
        $error_view,
        $panel,
      ),
      array(
        'title' => 'Login',
      ));
  }

}
