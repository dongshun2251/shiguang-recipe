# 食光菜谱

一个可自托管的家庭菜谱系统：PHP + MySQL 网站、Android 手机/平板 WebView 客户端、Windows Electron 桌面客户端。

线上演示地址：<https://dongshun.gt.tc/>

## 项目结构

```text
website/               PHP 网站
  admin/               后台
  api/                 接口
  assets/              样式与脚本
  inc/                 公共逻辑
  install/             安装向导
  sql/schema.sql       数据库结构
apps/android/          Android 手机客户端（包名 com.shiguang.recipe）
apps/android-tablet/   Android 平板客户端（包名 com.shiguang.recipe.tablet）
apps/desktop/          Windows Electron 客户端
releases/              已构建的安装包
```

## 网站部署

1. 新建 MySQL 数据库，导入 `website/sql/schema.sql`。
2. 复制 `website/config/config.example.php` 为 `website/config/config.php`，填写数据库地址、库名、账号、密码。
3. 把 `website/` 里的文件上传到网站根目录，确保根目录直接有 `index.php`。
4. 访问域名。也可以删除 `config/config.php` 后访问 `/install/`，由安装向导建库建表。

要求：PHP 8.0+、`pdo_mysql`、`ZipArchive`；`config/` 和 `uploads/` 需要可写。

## 客户端

### Android 手机 / 平板

```bash
cd apps/android
# 先把 keystore.properties.example 复制为 keystore.properties，填入自己的签名信息
./gradlew :app:assembleRelease
```

平板工程同理，目录是 `apps/android-tablet`。两个客户端都只是 WebView 壳，打开 `https://dongshun.gt.tc/`；改域名时同时改 `MainActivity.java` 里的 `SITE_URL` / `SITE_HOST`。

### Windows 桌面

```bash
cd apps/desktop
npm install
npm run build:win        # NSIS 安装包，Windows 上构建最稳
npm run pack:win        # 免安装绿色版
```

桌面端入口在 `main.js`，改域名时同步改 `SITE_URL` / `SITE_HOST`。

## 安装包

`releases/` 里放了：

- `食光菜谱-Android-1.0.5.apk`
- `食光菜谱-平板-1.0.5.apk`
- `食光菜谱-便携版-1.0.5.exe`（68 MB，不提交到 Git，发布到 GitHub Releases）

Windows 安装包没有商业代码签名，SmartScreen 可能提示“未知发布者”，选择“更多信息 → 仍要运行”。

## 安全说明

仓库**不包含**：

- `config/config.php`（数据库密码）
- `uploads/`（用户上传内容）
- `shots/`（开发截图）
- Android 签名证书和口令

发布前请确认这些文件没有被提交。构建安卓正式包需要自己的 keystore；仓库只提供 `keystore.properties.example` 模板。

## 版本

当前网站版本见 `website/inc/bootstrap.php` 里的 `SG_VERSION`。客户端版本见各自的 `build.gradle` 或 `package.json`。
