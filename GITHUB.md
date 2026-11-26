# 发布到 GitHub

这个目录已经初始化成 Git 仓库，当前分支 `main`，并且已经有一次提交。你只需要在 GitHub 建一个空仓库，然后把本地仓库推上去。

## 一、在网页上新建仓库

1. 登录 <https://github.com>。
2. 右上角 `+` → `New repository`。
3. 仓库名填 `shiguang-recipe`，描述可填“食光菜谱：网站与三端客户端”。
4. 选择 `Public`（公开）或 `Private`（私有）。
5. **不要勾选** `Add a README file`、`.gitignore`、`license`，因为本地仓库里已经有这些文件。
6. 点 `Create repository`。

## 二、把本地仓库推上去

在仓库目录执行（把 `<你的用户名>` 换成 GitHub 用户名）：

```bash
git remote add origin https://github.com/<你的用户名>/shiguang-recipe.git
git branch -M main
git push -u origin main
```

如果 `origin` 已存在，用：

```bash
git remote set-url origin https://github.com/<你的用户名>/shiguang-recipe.git
git push -u origin main
```

推送时会要求登录。推荐用 GitHub Desktop 或在 GitHub 设置里创建 Personal Access Token（Fine-grained token，勾选 `Contents: Read and write`），不要把账号密码直接写进命令。

## 三、发布安装包

进入仓库页面右侧的 `Releases` → `Create a new release`：

- Tag：`v1.0.5`
- Title：`食光菜谱 1.0.5`
- 上传：
  - `releases/食光菜谱-Android-1.0.5.apk`
  - `releases/食光菜谱-平板-1.0.5.apk`
  - `食光菜谱-Windows-1.0.5-便携版.exe`（68 MB，不提交到 Git，作为 Release 附件上传）
- 点 `Publish release`。

## 四、不要提交的内容

仓库已经用 `.gitignore` 排除了：

- `website/config/config.php`：数据库密码
- `website/uploads/`：用户上传内容
- `website/shots/`：开发截图
- Android `keystore/`、`keystore.properties`：签名证书和口令
- `node_modules/`、构建产物、Windows 便携版 exe

如果以后新增了配置文件，先在 `git status` 里确认没有被加入，再提交。
