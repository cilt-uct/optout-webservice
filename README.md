# optout-webservice

scripts/opencast/opt-out/optout_dept.pl
scripts/opencast/retention/retention_emails.pl

TODO: metadata set "Series Locked" for series

https://symfony.com/doc/3.3/frontend/encore/simple-example.html

install composer globally on windows (also add to the PATH system environment variables) https://getcomposer.org/download/ 
install node js https://nodejs.org/en/download 

## inside the local optout repo 
```
cp .env.template .env
composer install 
```
```
npm install from inside project folder
```
```
# Compile assets once
./node_modules/.bin/encore dev

# Compile assets automatically when files change
./node_modules/.bin/encore dev --watch

# Compile assets, but also minify & optimize them
./node_modules/.bin/encore production
```

# How to update
make sure in composer.json, the php version matches your local php version, aslo adding the symphony required package
composer require symfony/webpack-encore-bundle

npm install webpack --save-dev 
```
 "require": {
        "php": "[php version],
        ...
```
```
composer update
