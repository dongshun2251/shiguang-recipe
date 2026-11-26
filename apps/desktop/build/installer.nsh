!macro customInstall
  ; 下载页“打开 App”：安装时直接注册 shiguang://，不必等第一次启动
  WriteRegStr HKCU "Software\Classes\shiguang" "" "URL:shiguang Protocol"
  WriteRegStr HKCU "Software\Classes\shiguang" "URL Protocol" ""
  WriteRegStr HKCU "Software\Classes\shiguang\shell\open\command" "" '"$INSTDIR\${APP_EXECUTABLE_FILENAME}" "%1"'
!macroend

!macro customUnInstall
  DeleteRegKey HKCU "Software\Classes\shiguang"
!macroend
