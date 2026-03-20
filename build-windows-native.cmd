@echo off
cd target
native-image --enable-url-protocols=http,https -jar radio-pipe-1.0.jar
cd ..
