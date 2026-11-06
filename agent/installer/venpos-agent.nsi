; VenPos Agent installer (PRD I1, I2, AC9). Unsigned on purpose (I2).
;
; Build (from agent/, after ./build.sh or cargo build --release):
;   makensis -INPUTCHARSET UTF8 -DVERSION=0.1.1 installer/venpos-agent.nsi
;   (the script is UTF-8 with BOM; -INPUTCHARSET UTF8 keeps it right even if an
;   editor drops the BOM: makensis would read the ANSI code page and garble text)
; Optional defines: BINDIR (folder with both .exe), OUTFILE.
;
; Silent install (CI, mass deployment):
;   VenPosAgent-setup.exe /S [/SERVER=https://tpv.example] [/CODE=123456]
;                            [/PRINTER=Ticket queue] [/LABELPRINTER=Label queue] [/TESTPRINT]
;   Without /CODE the agent is installed unpaired (pair later from the
;   tray, "Conectar con el TPV…", by running the installer again or with
;   `venpos-agent pair`). Printer names must not contain " /".
;   Exit codes: 0 ok, 1 cancelled/other, 2 aborted by NSIS, 3 code rejected,
;   4 key rejected, 5 server unreachable, 6 printer error, 7 needs administrator
;   (the agent CLI codes, see venpos-agent/src/main.rs).
; Silent uninstall: uninstall.exe /S [/PURGE] (/PURGE also deletes
;   %ProgramData%\VenPos Agent: configuration, key and log).

Unicode true
ManifestDPIAware true
RequestExecutionLevel admin
SetCompressor /SOLID lzma

!include "MUI2.nsh"
!include "nsDialogs.nsh"
!include "LogicLib.nsh"
!include "FileFunc.nsh"
!include "x64.nsh"
!include "WinMessages.nsh"

!ifndef VERSION
  !define VERSION "0.1.1"
!endif
!ifndef BINDIR
  !define BINDIR "..\dist\windows-x86_64"
!endif
!ifndef OUTFILE
  !define OUTFILE "..\dist\VenPosAgent-${VERSION}-setup.exe"
!endif

!define PRODUCT "VenPos Agent"
!define SERVICE "VenPosAgent"
!define AGENT "venpos-agent.exe"
!define TRAY "venpos-agent-tray.exe"
!define UNINST_KEY "Software\Microsoft\Windows\CurrentVersion\Uninstall\VenPosAgent"
!define RUN_KEY "Software\Microsoft\Windows\CurrentVersion\Run"
!define RUN_VALUE "VenPosAgentTray"

Name "${PRODUCT}"
OutFile "${OUTFILE}"
InstallDir "$PROGRAMFILES64\${PRODUCT}"
BrandingText "${PRODUCT} ${VERSION}"
ShowInstDetails show
ShowUninstDetails show

VIProductVersion "${VERSION}.0"
VIFileVersion "${VERSION}.0"
VIAddVersionKey /LANG=1034 "ProductName" "${PRODUCT}"
VIAddVersionKey /LANG=1034 "FileDescription" "Instalador del agente de impresión de VenPos"
VIAddVersionKey /LANG=1034 "FileVersion" "${VERSION}"
VIAddVersionKey /LANG=1034 "ProductVersion" "${VERSION}"
VIAddVersionKey /LANG=1034 "CompanyName" "VenPos"
VIAddVersionKey /LANG=1034 "LegalCopyright" "VenPos"

Var Server
Var Code
Var PairLater
Var TicketPrinter
Var LabelPrinter
Var TestPrint
Var Paired
Var ServiceExisted
; Page controls
Var hServer
Var hCode
Var hLater
Var hTicket
Var hLabel
Var hTest

!define MUI_ABORTWARNING
; Brand mark (docs/brand/venpostpv-mark.svg, same file as public/favicon.ico);
; makensis runs from this folder, so the path is relative to it.
!define MUI_ICON "venpos.ico"
!define MUI_UNICON "venpos.ico"
!define MUI_WELCOMEPAGE_TITLE "Instalar ${PRODUCT} ${VERSION}"
!define MUI_WELCOMEPAGE_TEXT "Este asistente instala el agente de impresión en este ordenador de caja.$\r$\n$\r$\nEl agente recoge del TPV los tickets, la apertura del cajón y las etiquetas de esta caja y los envía a sus impresoras.$\r$\n$\r$\nTen a mano la dirección del TPV y el código de 6 cifras de Ajustes → Cajas (también puedes emparejar más tarde)."
!insertmacro MUI_PAGE_WELCOME
Page custom PairPageCreate PairPageLeave
Page custom PrinterPageCreate PrinterPageLeave
!insertmacro MUI_PAGE_INSTFILES
!define MUI_FINISHPAGE_TEXT "El servicio ${PRODUCT} está instalado y en marcha. El icono de la bandeja aparece junto al reloj al iniciar sesión."
!define MUI_FINISHPAGE_RUN
!define MUI_FINISHPAGE_RUN_TEXT "Abrir ahora el icono de la bandeja"
!define MUI_FINISHPAGE_RUN_FUNCTION LaunchTray
!insertmacro MUI_PAGE_FINISH

!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES

!insertmacro MUI_LANGUAGE "Spanish"

; ---------------------------------------------------------------- helpers

; Removes trailing CR/LF from the value on top of the stack.
Function TrimNewline
  Exch $0
  Push $1
  loop:
    StrCpy $1 $0 1 -1
    ${If} $1 == "$\r"
    ${OrIf} $1 == "$\n"
      StrCpy $0 $0 -1
      Goto loop
    ${EndIf}
  Pop $1
  Exch $0
FunctionEnd

; Spanish explanation for an agent exit code (top of stack) -> top of stack.
Function ExplainExit
  Exch $0
  ${Switch} $0
    ${Case} 3
      StrCpy $0 "El código no es válido, ya se usó o ha caducado. Genera uno nuevo en Ajustes → Cajas."
      ${Break}
    ${Case} 4
      StrCpy $0 "El TPV ha rechazado la clave del agente. Vuelve a conectar el agente desde Ajustes → Cajas."
      ${Break}
    ${Case} 5
      StrCpy $0 "No se puede conectar con el TPV. Revisa la dirección y la conexión a Internet."
      ${Break}
    ${Case} 6
      StrCpy $0 "La impresora ha dado un error. Revisa que esté encendida y que su driver sea v3 o «Generic / Text Only»."
      ${Break}
    ${Case} 7
      StrCpy $0 "Hace falta ejecutar el instalador como administrador."
      ${Break}
    ${Default}
      StrCpy $0 "Ha fallado la operación."
  ${EndSwitch}
  Exch $0
FunctionEnd

; Runs `"exe" args`; on failure shows (or, silent, logs) the explanation and
; the agent's own message. Leaves the exit code in $0.
!macro RunAgent EXE ARGS
  DetailPrint `venpos-agent ${ARGS}`
  nsExec::ExecToStack `"${EXE}" ${ARGS}`
  Pop $0
  Pop $1
  DetailPrint "$1"
!macroend

Function ShowAgentError
  ; $0 = exit code, $1 = agent output
  Push $0
  Call ExplainExit
  Pop $2
  ${IfNot} ${Silent}
    MessageBox MB_OK|MB_ICONEXCLAMATION "$2$\r$\n$\r$\nDetalle: $1"
  ${EndIf}
  DetailPrint "$2"
FunctionEnd

; Deletes a file that a stopping process may still hold (up to 30 s).
!macro DeleteRetry FILE
  Push $9
  StrCpy $9 0
  ${Do}
    ClearErrors
    Delete "${FILE}"
    ${IfNot} ${FileExists} "${FILE}"
      ${Break}
    ${EndIf}
    IntOp $9 $9 + 1
    ${If} $9 > 60
      ${Break}
    ${EndIf}
    Sleep 500
  ${Loop}
  Pop $9
!macroend

; ---------------------------------------------------------------- init

Function .onInit
  ${IfNot} ${RunningX64}
    MessageBox MB_OK|MB_ICONSTOP "${PRODUCT} necesita Windows 10 u 11 de 64 bits." /SD IDOK
    SetErrorLevel 1
    Quit
  ${EndIf}
  SetRegView 64
  SetShellVarContext all
  StrCpy $Server "https://"
  StrCpy $PairLater ${BST_UNCHECKED}
  StrCpy $TestPrint ${BST_CHECKED}
  StrCpy $Paired 0
  ${GetParameters} $R0
  ClearErrors
  ${GetOptions} $R0 "/SERVER=" $R1
  ${IfNot} ${Errors}
    StrCpy $Server $R1
  ${EndIf}
  ClearErrors
  ${GetOptions} $R0 "/CODE=" $Code
  ClearErrors
  ${GetOptions} $R0 "/PRINTER=" $TicketPrinter
  ClearErrors
  ${GetOptions} $R0 "/LABELPRINTER=" $LabelPrinter
  ${If} ${Silent}
    ; Silent: test print only on request.
    StrCpy $TestPrint ${BST_UNCHECKED}
    ClearErrors
    ${GetOptions} $R0 "/TESTPRINT" $R1
    ${IfNot} ${Errors}
      StrCpy $TestPrint ${BST_CHECKED}
    ${EndIf}
  ${EndIf}
  ; The agent is needed before the files are installed (pairing page, printer list).
  InitPluginsDir
  SetOutPath "$PLUGINSDIR"
  File "/oname=$PLUGINSDIR\${AGENT}" "${BINDIR}\${AGENT}"
FunctionEnd

; ---------------------------------------------------------------- page 1: pairing

Function PairPageCreate
  !insertmacro MUI_HEADER_TEXT "Conectar con el TPV" "Empareja esta caja con el código de Ajustes → Cajas."
  nsDialogs::Create 1018
  Pop $R0
  ${If} $R0 == error
    Abort
  ${EndIf}
  ${NSD_CreateLabel} 0 0 100% 24u "En el TPV, abre Ajustes → Cajas y genera el código de emparejado de esta caja. Escribe aquí la dirección del TPV y el código de 6 cifras (caduca a los 10 minutos)."
  Pop $R0
  ${NSD_CreateLabel} 0 30u 100% 10u "Dirección del TPV:"
  Pop $R0
  ${NSD_CreateText} 0 41u 100% 12u "$Server"
  Pop $hServer
  ${NSD_CreateLabel} 0 60u 100% 10u "Código de 6 cifras:"
  Pop $R0
  ${NSD_CreateNumber} 0 71u 60u 12u "$Code"
  Pop $hCode
  SendMessage $hCode ${EM_LIMITTEXT} 6 0
  ${NSD_CreateCheckbox} 0 92u 100% 12u "Emparejar más tarde (desde el icono → «Conectar con el TPV…»)"
  Pop $hLater
  ${NSD_SetState} $hLater $PairLater
  ${NSD_OnClick} $hLater PairLaterClicked
  Push $hLater ; same stack shape as the click callback
  Call PairLaterClicked
  nsDialogs::Show
FunctionEnd

Function PairLaterClicked
  Pop $R9 ; the clicked control
  ${NSD_GetState} $hLater $R0
  ${If} $R0 == ${BST_CHECKED}
    EnableWindow $hServer 0
    EnableWindow $hCode 0
  ${Else}
    EnableWindow $hServer 1
    EnableWindow $hCode 1
  ${EndIf}
FunctionEnd

Function PairPageLeave
  ${NSD_GetText} $hServer $Server
  ${NSD_GetText} $hCode $Code
  ${NSD_GetState} $hLater $PairLater
  ${If} $PairLater == ${BST_CHECKED}
    Return
  ${EndIf}
  ${If} $Paired == 1
    Return
  ${EndIf}
  StrLen $R0 $Code
  ${If} $R0 != 6
    MessageBox MB_OK|MB_ICONEXCLAMATION "El código tiene 6 cifras. Si no lo tienes, marca «Emparejar más tarde»."
    Abort
  ${EndIf}
  !insertmacro RunAgent "$PLUGINSDIR\${AGENT}" 'pair "$Code" --server "$Server"'
  ${If} $0 != 0
    Call ShowAgentError
    Abort ; stay on the page
  ${EndIf}
  StrCpy $Paired 1
  Push $1
  Call TrimNewline
  Pop $1
  MessageBox MB_OK|MB_ICONINFORMATION "$1"
FunctionEnd

; ---------------------------------------------------------------- page 2: printers

Function AddPrinters
  ; $R0 = drop list; fills it from $PLUGINSDIR\printers.txt (UTF-16LE, BOM).
  ClearErrors
  FileOpen $R1 "$PLUGINSDIR\printers.txt" r
  ${If} ${Errors}
    Return
  ${EndIf}
  FileSeek $R1 2 SET ; skip the BOM
  ${Do}
    ClearErrors
    FileReadUTF16LE $R1 $R2
    ${If} ${Errors}
      ${Break}
    ${EndIf}
    Push $R2
    Call TrimNewline
    Pop $R2
    ${If} $R2 != ""
      ${NSD_CB_AddString} $R0 "$R2"
    ${EndIf}
  ${Loop}
  FileClose $R1
FunctionEnd

Function PrinterPageCreate
  !insertmacro MUI_HEADER_TEXT "Elegir impresoras" "Impresoras instaladas para todo el equipo."
  nsExec::ExecToStack '"$PLUGINSDIR\${AGENT}" list-printers --names-file "$PLUGINSDIR\printers.txt"'
  Pop $R0
  Pop $R0
  nsDialogs::Create 1018
  Pop $R0
  ${If} $R0 == error
    Abort
  ${EndIf}
  ${NSD_CreateLabel} 0 0 100% 24u "Elige la impresora de tickets y, si la tienes, la de etiquetas. Si no aparece, instálala antes en Windows con un driver v3 o «Generic / Text Only» (los driver v4 pueden no imprimir)."
  Pop $R0
  ${NSD_CreateLabel} 0 30u 100% 10u "Impresora de tickets:"
  Pop $R0
  ${NSD_CreateDropList} 0 41u 100% 80u ""
  Pop $hTicket
  ${NSD_CB_AddString} $hTicket "(elegir más tarde)"
  StrCpy $R0 $hTicket
  Call AddPrinters
  ${NSD_CreateLabel} 0 60u 100% 10u "Impresora de etiquetas:"
  Pop $R0
  ${NSD_CreateDropList} 0 71u 100% 80u ""
  Pop $hLabel
  ${NSD_CB_AddString} $hLabel "(ninguna)"
  StrCpy $R0 $hLabel
  Call AddPrinters
  ${NSD_CreateCheckbox} 0 92u 100% 12u "Imprimir una página de prueba al terminar"
  Pop $hTest
  ${NSD_SetState} $hTest $TestPrint
  ${If} $TicketPrinter == ""
    ${NSD_CB_SelectString} $hTicket "(elegir más tarde)"
  ${Else}
    ${NSD_CB_SelectString} $hTicket "$TicketPrinter"
  ${EndIf}
  ${If} $LabelPrinter == ""
    ${NSD_CB_SelectString} $hLabel "(ninguna)"
  ${Else}
    ${NSD_CB_SelectString} $hLabel "$LabelPrinter"
  ${EndIf}
  nsDialogs::Show
FunctionEnd

Function PrinterPageLeave
  ${NSD_GetText} $hTicket $TicketPrinter
  ${NSD_GetText} $hLabel $LabelPrinter
  ${NSD_GetState} $hTest $TestPrint
  ${If} $TicketPrinter == "(elegir más tarde)"
    StrCpy $TicketPrinter ""
  ${EndIf}
  ${If} $LabelPrinter == "(ninguna)"
    StrCpy $LabelPrinter ""
  ${EndIf}
FunctionEnd

; ---------------------------------------------------------------- install

Section "Instalar" SecMain
  SetRegView 64
  SetShellVarContext all

  ; Upgrade: stop the running service and tray so their files can be replaced.
  nsExec::ExecToStack 'sc query ${SERVICE}'
  Pop $0
  Pop $1
  ; Only a successful query means "already installed"; any other result
  ; (1060 = does not exist) installs the service from scratch.
  ${If} $0 != 0
    StrCpy $ServiceExisted 0
  ${Else}
    StrCpy $ServiceExisted 1
    DetailPrint "Parando el servicio ${SERVICE}…"
    nsExec::ExecToStack 'sc stop ${SERVICE}'
    Pop $0
    Pop $1
  ${EndIf}
  nsExec::ExecToStack 'taskkill /F /IM ${TRAY}'
  Pop $0
  Pop $1
  !insertmacro DeleteRetry "$INSTDIR\${AGENT}"
  !insertmacro DeleteRetry "$INSTDIR\${TRAY}"

  SetOutPath "$INSTDIR"
  File "${BINDIR}\${AGENT}"
  File "${BINDIR}\${TRAY}"
  WriteUninstaller "$INSTDIR\uninstall.exe"

  WriteRegStr HKLM "${UNINST_KEY}" "DisplayName" "${PRODUCT}"
  WriteRegStr HKLM "${UNINST_KEY}" "DisplayVersion" "${VERSION}"
  WriteRegStr HKLM "${UNINST_KEY}" "Publisher" "VenPos"
  WriteRegStr HKLM "${UNINST_KEY}" "DisplayIcon" "$INSTDIR\${TRAY}"
  WriteRegStr HKLM "${UNINST_KEY}" "InstallLocation" "$INSTDIR"
  WriteRegStr HKLM "${UNINST_KEY}" "UninstallString" '"$INSTDIR\uninstall.exe"'
  WriteRegStr HKLM "${UNINST_KEY}" "QuietUninstallString" '"$INSTDIR\uninstall.exe" /S'
  WriteRegDWORD HKLM "${UNINST_KEY}" "NoModify" 1
  WriteRegDWORD HKLM "${UNINST_KEY}" "NoRepair" 1
  ${GetSize} "$INSTDIR" "/S=0K" $0 $1 $2
  IntFmt $0 "0x%08X" $0
  WriteRegDWORD HKLM "${UNINST_KEY}" "EstimatedSize" "$0"

  ; Silent pairing (the wizard already paired on its page).
  ${If} ${Silent}
  ${AndIf} $Code != ""
    !insertmacro RunAgent "$INSTDIR\${AGENT}" 'pair "$Code" --server "$Server"'
    ${If} $0 != 0
      Call ShowAgentError
      SetErrorLevel $0
      Abort "No se pudo emparejar."
    ${EndIf}
  ${EndIf}

  ${If} $TicketPrinter != ""
    !insertmacro RunAgent "$INSTDIR\${AGENT}" 'set-printer --target ticket --spooler "$TicketPrinter"'
    ${If} $0 != 0
      Call ShowAgentError
    ${EndIf}
  ${EndIf}
  ${If} $LabelPrinter != ""
    !insertmacro RunAgent "$INSTDIR\${AGENT}" 'set-printer --target label --spooler "$LabelPrinter"'
    ${If} $0 != 0
      Call ShowAgentError
    ${EndIf}
  ${EndIf}

  ${If} $ServiceExisted == 1
    DetailPrint "Arrancando el servicio ${SERVICE}…"
    nsExec::ExecToStack 'sc start ${SERVICE}'
    Pop $0
    Pop $1
    DetailPrint "$1"
  ${Else}
    !insertmacro RunAgent "$INSTDIR\${AGENT}" "install"
    ${If} $0 != 0
      Call ShowAgentError
      SetErrorLevel $0
      Abort "No se pudo instalar el servicio."
    ${EndIf}
  ${EndIf}

  ; Tray for every user at logon (A1).
  WriteRegStr HKLM "${RUN_KEY}" "${RUN_VALUE}" '"$INSTDIR\${TRAY}"'

  ${If} $TestPrint == ${BST_CHECKED}
  ${AndIf} $TicketPrinter != ""
    !insertmacro RunAgent "$INSTDIR\${AGENT}" "test-print --target ticket"
    ${If} $0 != 0
      Call ShowAgentError
      ${If} ${Silent}
        SetErrorLevel $0
      ${EndIf}
    ${EndIf}
  ${EndIf}
SectionEnd

Function LaunchTray
  ; Through Explorer so the tray runs as the logged-on user, not elevated.
  Exec '"$WINDIR\explorer.exe" "$INSTDIR\${TRAY}"'
FunctionEnd

; ---------------------------------------------------------------- uninstall

Function un.onInit
  SetRegView 64
  SetShellVarContext all
FunctionEnd

Section "Uninstall"
  SetRegView 64
  SetShellVarContext all
  nsExec::ExecToStack 'taskkill /F /IM ${TRAY}'
  Pop $0
  Pop $1
  DetailPrint "venpos-agent uninstall"
  nsExec::ExecToStack '"$INSTDIR\${AGENT}" uninstall'
  Pop $0
  Pop $1
  DetailPrint "$1"
  !insertmacro DeleteRetry "$INSTDIR\${AGENT}"
  !insertmacro DeleteRetry "$INSTDIR\${TRAY}"
  Delete "$INSTDIR\uninstall.exe"
  RMDir "$INSTDIR"
  DeleteRegValue HKLM "${RUN_KEY}" "${RUN_VALUE}"
  DeleteRegKey HKLM "${UNINST_KEY}"

  ReadEnvStr $2 "ProgramData"
  StrCpy $2 "$2\${PRODUCT}"
  ${un.GetParameters} $R0
  ClearErrors
  ${un.GetOptions} $R0 "/PURGE" $R1
  ${If} ${Errors}
    StrCpy $3 0
    ${IfNot} ${Silent}
      MessageBox MB_YESNO|MB_ICONQUESTION|MB_DEFBUTTON2 "¿Borrar también la configuración, la clave de emparejado y el registro ($2)?$\r$\n$\r$\nSi vas a reinstalar el agente en esta caja, pulsa No." IDNO keep
      StrCpy $3 1
      keep:
    ${EndIf}
  ${Else}
    StrCpy $3 1
  ${EndIf}
  ${If} $3 == 1
    RMDir /r "$2"
    DetailPrint "Borrado $2"
  ${Else}
    DetailPrint "Se conserva $2"
  ${EndIf}
SectionEnd
