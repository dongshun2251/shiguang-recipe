# 客户端构建

## Android

需要 JDK 17 和 Android SDK 34。

```bash
cd android
cp keystore.properties.example keystore.properties
# 编辑 keystore.properties，填自己的 jks 路径和口令
./gradlew :app:assembleRelease
```

平板：把上面的 `android` 换成 `android-tablet`。

## Windows

需要 Node.js 18+。Windows 本机执行 `npm run build:win` 最稳；Linux 上构建 NSIS 需要 wine。

```bash
cd desktop
npm install
npm run build:win
```
