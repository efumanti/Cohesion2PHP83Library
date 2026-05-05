# Cohesion2 libreria PHP

> **Fork modernizzato per PHP 8.3+** del progetto originale
> [`andreaval/Cohesion2PHPLibrary`](https://github.com/andreaval/Cohesion2PHPLibrary)
> di Andrea Vallorani. Mantiene la stessa API pubblica della 3.x, alza il
> requisito minimo a PHP 8.3, riscrive le chiamate HTTPS con cURL (TLS
> verificato), corregge una serie di problemi di sicurezza (open redirect,
> XML injection, session fixation, XXE, …) e introduce una suite PHPUnit
> con analisi statica PHPStan a livello max. Vedi la sezione "Storia del
> progetto" in fondo per il dettaglio.

Libreria per l'autenticazione al sistema di SSO Cohesion2 della Regione Marche.
Questa libreria permette di integrare il Single Sign-On di Cohesion2 in siti o
applicativi web sviluppati in linguaggio PHP.

## Requisiti di installazione
* PHP 8.3 o superiore
* Estensione PHP `curl` abilitata (richiesta per le chiamate HTTPS verso Cohesion2)
* Estensioni PHP `dom`, `libxml`, `simplexml` (di norma incluse nel pacchetto standard)

## Installazione
Tramite [Composer](https://getcomposer.org/):

```bash
composer require efumanti/cohesion2-library
```

oppure **manualmente** copiando la directory `cohesion2/` in un qualsiasi punto
della cartella web dell'applicativo. Assicurarsi che la cartella contenga i file:
- `Cohesion2.php`
- `Cohesion2Exception.php`

## Abilitazione SSO
Il Single Sign-On è abilitato per default nella libreria. Questo significa che prima di reindirizzare l’utente alla maschera di login, il sistema verifica la validità della sessione ed evita quindi all’utente di doversi riautenticare.
Per disabilitare, eventualmente il SSO, e forzare quindi sempre all’autenticazione, utilizzare il seguente comando:

```php
      $cohesion = new Cohesion2;
      $cohesion->useSSO(false);
      $cohesion->auth();
```

### Esempio di utilizzo

```php
      require_once 'cohesion2/Cohesion2.php';
      use andreaval\Cohesion2\Cohesion2;
      use andreaval\Cohesion2\Cohesion2Exception;
      try{
          $cohesion = new Cohesion2;
          $cohesion->auth();
      }
      catch(Cohesion2Exception $e){
          die($e->getMessage());
      }
      if($cohesion->isAuth()){
          echo 'Utente autenticato: '.$cohesion->username.'<br>';
          echo 'Id SSO: '.$cohesion->id_sso.'<br>';
          echo 'Id Aspnet: '.$cohesion->id_aspnet.'<br>';
          echo 'Profilo: <pre>'.var_export($cohesion->profile,1).'</pre>';
      } 
```

## Identificativo applicativo (`id_sito`)

Cohesion2 identifica l'applicazione client tramite il campo `<id_sito>`
incluso nel payload di richiesta. Fino alla 3.x questo valore era
hardcoded a `'TEST'`. Dalla 4.0.0 si imposta tramite il quarto parametro
del costruttore o via variabile d'ambiente `COHESION2_SITE_ID`.

```php
$cohesion = new Cohesion2('cohesion2', null, null, 'PORTALE_AZIENDA');
```

oppure:

```
COHESION2_SITE_ID=PORTALE_AZIENDA
```

Il default rimane `'TEST'` per compatibilità con installazioni esistenti,
ma è da sostituire prima del passaggio in produzione concordando il valore
con il referente Cohesion2 della Regione Marche.

## Configurazione di sicurezza: whitelist degli host di callback

L'URL di callback inviata a Cohesion2 al termine dell'autenticazione viene
costruita a partire dall'host della richiesta corrente. L'header HTTP `Host`
è però controllato dal client e, in assenza di una validazione, può essere
manipolato per indurre Cohesion2 a redirigere l'utente verso un host
arbitrario subito dopo il login (open redirect / token leakage, CWE-601).

A partire dalla 4.0.0 la libreria accetta una whitelist di host autorizzati.
Quando la whitelist è popolata, l'header `Host` deve corrispondere
(case-insensitive) a uno dei valori dichiarati: in caso contrario `auth()`
solleva `Cohesion2Exception`. Quando la whitelist è vuota, la libreria usa
`$_SERVER['SERVER_NAME']` (configurato lato web server) anziché `HTTP_HOST`.

**Impostazione via costruttore:**

```php
$cohesion = new Cohesion2('cohesion2', ['app.example.it', 'www.example.it']);
$cohesion->auth();
```

**Impostazione via variabile d'ambiente** (utile con `.env`):

```
COHESION2_ALLOWED_HOSTS=app.example.it,www.example.it
```

```php
// La libreria legge automaticamente $_ENV / $_SERVER / getenv() in questo
// ordine. È compatibile con vlucas/phpdotenv, Symfony Dotenv e Laravel.
$cohesion = new Cohesion2();
$cohesion->auth();
```

In ambiente di produzione si consiglia di **dichiarare sempre** la whitelist.

### Applicazioni dietro un reverse proxy / load balancer

Quando il TLS è terminato a monte (load balancer, CDN, ingress controller),
`$_SERVER['SERVER_PORT']` vale 80 e la libreria, in assenza di altri segnali,
costruirebbe una callback URL `http://…` esponendo il token nel redirect.

Il terzo parametro del costruttore — `trustProxy` — abilita la lettura di
`X-Forwarded-Proto` per determinare lo schema. **Va attivato solo se l'app
è raggiungibile esclusivamente tramite il proxy**: l'header è altrimenti
spoofabile.

```php
$cohesion = new Cohesion2('cohesion2', null, true);
```

oppure via variabile d'ambiente:

```
COHESION2_TRUST_PROXY=1
```

## Abilitazione SAML 2.0
E' possibile indicare a Cohesion di utilizzare lo standard SAML 2.0 tramite l'apposito metodo **useSAML20()** . L'utilizzo di tale metodo permette agli utenti di autenticarsi anche tramite sistema SPID.

```php
      $cohesion = new Cohesion2;
      $cohesion->useSAML20(true);
      $cohesion->enableEIDASLogin(); //per abilitare il login eIDAS
      $cohesion->enableSPIDProLogin(['PF','PG','LP']); //per abilitare il login SPID Professionale
      $cohesion->auth();
```

## Spiegazione del meccanismo di autenticazione
Invocando il metodo auth() della classe Cohesion2 viene avviato il processo di autenticazione tramite SSO. Il processo si svolge in 4 passi:

1. Viene invocata la pagina web https://cohesion2.regione.marche.it/sso/Check.aspx per verificare se l’utente risulti già autenticato tramite SSO
2. Nel caso l’utente non sia autenticato, il browser dell’utente viene automaticamente reindirizzato alla pagina di login https://cohesion2.regione.marche.it/SA/AccediCohesion.aspx
3. Se l’autenticazione ha esito positivo, la libreria istanzia una variabile di sessione per tenere traccia dell’avvenuta autenticazione ed invoca la pagina web https://cohesion2.regione.marche.it/SSO/webCheckSessionSSO.aspx per recuperare il profilo dell’utente autenticato
4. Se il recupero del profilo è avvenuto correttamente i dati dell’utente saranno accessibili tramite le seguenti proprietà dell’oggetto istanziato:
- `$cohesion->username` (Username utente autenticato)
- `$cohesion->id_sso` (ID della sessione SSO)
- `$cohesion->id_aspnet` (ID della sessione ASPNET)
- `$cohesion->profile` (Array contenente il profilo della persona)

Per la configurazione SAML 2.0 il funzionamento è analogo, cambiano solamente gli endpoint utilizzati e le possibilita di accesso per l'utente (SPID, CIE-ID, CNS, Cohesion, eIDAS, ...)

## Profilo utente autenticato
Tramite la proprietà  *profile*  è possibile accedere ai dati del profilo utente. 
I valori ritornati dal sistema di autenticazione vengono istanziati come chiavi dell’array profile (non tutti i campi possono risultare valorizzati).

Cohesion2 restituisce i seguenti campi:
titolo, nome, cognome, sesso, login, codice_fiscale, telefono, localita_nascita, provincia_nascita, cap_nascita, regione_nascita,
data_nascita, nazione_nascita, gruppo, ruolo, email, email_certificata, telefono_ufficio, fax_ufficio, numero_cellulare, 
indirizzo_residenza, localita_residenza, provincia_residenza, cap_residenza, regione_residenza, nazione_residenza, professione, 
settore_azienda, profilo_familiare, tipo_autenticazione (PW , PIN , CF).

SAML 2.0 restituisce i seguenti campi:
- address (alias: indirizzo_residenza)
- companyFiscalNumber (alias: Codice_fiscale_persona_giuridica)
- countyOfBirth (alias: provincia_nascita)
- dateOfBirth (data nel formato inglese yyyy-mm-dd)
- data_nascita (data nel formato italiano gg/mm/aaaa)
- digitalAddress
- email_certificata
- email (alias: Indirizzo_di_posta_elettronica)
- familyName (alias: cognome)
- fiscalNumber (codice fiscale preceduto da TINIT-)
- codice_fiscale
- gender (alias: sesso)
- ivaCode (alias: Partita_IVA)
- name (alias: nome)
- placeOfBirth (codice istat comune di nascita)
- localita_nascita (nome del comune di nascita)
- spidCode (alias: Codice_identificativo_SPID)
- tipo_autenticazione
- login (contiene il codice fiscale)

Esempio:

```php
      echo $cohesion->profile['nome'].' '.$cohesion->profile['cognome'];
```

## Procedura di logout
Per chiudere la sessione locale e disconnettere l’utente dal sistema di SSO utilizzare il metodo logout():

```php
      $cohesion = new Cohesion2;
      $cohesion->logout();
```

L’esempio completo è visualizzabile nel file *test/logout.php* o nel file *test/logout_saml20.php*
Per chiudere, eventualmente, solo la sessione locale lasciando aperta quella del SSO utilizzare il metodo logoutLocal():

```php
      $cohesion = new Cohesion2;
      $cohesion->logoutLocal();
```

## Limitazione dei metodi di autenticazione permessi
Il metodo setAuthRestriction  permette di limitare i metodi di autenticazione permessi .

```php
      $cohesion->setAuthRestriction('1,2,3');
```

I valori 0,1,2,3 indicano i livelli di autenticazione da mostrare nella pagina di login Cohesion:

- 0 = autenticazione con Utente e Password
- 1 = autenticazione con Utente, Password e PIN
- 2 = autenticazione con Smart Card
- 3 = autenticazione di Dominio (valida solo per utenti interni alla rete regionale)

E’ possibile nascondere o visualizzare le modalità di autenticazione togliendo o aggiungendo i rispettivi valori separati da una virgola. L’ordine è ininfluente.

N.B. Se si intende limitare l’accesso in base al tipo di autenticazione, è necessario, oltre ad utilizzare tale metodo, inserire un controllo per gli utenti che risultino già autenticati in SSO.

```php
      if($cohesion->profile['tipo_autenticazione']!='PW'){
          echo 'OK puoi usare il servizio';
      }
      else echo 'Autenticazione debole non permessa';
```

## Autori e storia del progetto
Libreria creata come lavoro personale da Andrea Vallorani (andrea.vallorani@gmail.com)

- 2015-06-16 pubblicata ver. 2.1.0 https://github.com/andreaval/Cohesion2PHPLibrary/releases/tag/2.1.0
- 2015-06-31 pubblicata ver. 2.1.1 https://github.com/andreaval/Cohesion2PHPLibrary/releases/tag/2.1.1
- 2015-10-13 pubblicata ver. 2.1.2 https://github.com/andreaval/Cohesion2PHPLibrary/releases/tag/2.1.2
- 2018-04-25 pubblicata ver. 2.2.0 https://github.com/andreaval/Cohesion2PHPLibrary/releases/tag/2.2.0
- 2023-03-20 integrate modifiche di @xavbeta dal fork https://github.com/regione-marche/Cohesion2PHPLibrary)
- 2023-03-27 pubblicata ver. 3.0.0 https://github.com/andreaval/Cohesion2PHPLibrary/releases/tag/3.0.0
- 2026-05-04 ver. 4.0.0 — modernizzazione a PHP 8.3:
    - requisito minimo PHP 8.3, autoload PSR-4, dichiarazioni `strict_types` e tipizzazione completa di proprietà e metodi
    - chiamate HTTPS riscritte con cURL e verifica TLS attiva di default (rimossa la dipendenza da `allow_url_fopen`)
    - sessione salvata come array invece di oggetto serializzato (elimina il rischio di PHP Object Injection); le sessioni 3.x non sono compatibili
